<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Scraping;

use App\Models\IngestionBatch;
use App\Models\Location;
use App\Models\Source;
use App\Models\Submission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Runs a scraper against a source, page by page.
 *
 * Owns the three properties the scraper contract promises but cannot enforce on
 * its own: the run is rate limited, it resumes from a persisted cursor, and
 * every record lands under a deterministic idempotency key so a re-run — or a
 * resumed run that overlaps — cannot double-count.
 */
final class ScraperRunner
{
    /**
     * Pages fetched in one run.
     *
     * Bounded so a scheduled run has a predictable ceiling and a dataset that
     * paginates forever cannot occupy a worker indefinitely. The cursor means
     * the next run picks up where this one stopped.
     */
    private const MAX_PAGES_PER_RUN = 20;

    public function __construct(private readonly ScraperRegistry $registry) {}

    public function run(Source $source): IngestionBatch
    {
        /** @var array<string, mixed> $config */
        $config = $source->config ?? [];
        $key = (string) ($config['scraper'] ?? '');

        $batch = IngestionBatch::query()->create([
            'source_id' => $source->id,
            'filename' => 'scrape:'.$source->slug.':'.CarbonImmutable::now()->toDateTimeString(),
            'checksum' => null,
            'status' => IngestionBatch::STATUS_PROCESSING,
            'started_at' => CarbonImmutable::now(),
        ]);

        if (! $this->registry->has($key)) {
            return $this->fail($batch, "Source '{$source->slug}' names unknown scraper '{$key}'.");
        }

        $scraper = $this->registry->get($key);
        $delayMicroseconds = (int) round(60_000_000 / max(1, $scraper->requestsPerMinute()));

        $locations = $this->locationIndex($source);
        $cursor = $source->resumeCursor();

        $found = 0;
        $accepted = 0;
        $warnings = [];

        try {
            for ($page = 0; $page < self::MAX_PAGES_PER_RUN; $page++) {
                $result = $scraper->fetch($source, $cursor);

                $found += count($result->records);
                $warnings = [...$warnings, ...$result->warnings];

                $accepted += $this->store($batch, $source, $result->records, $locations);

                $cursor = $result->nextCursor;

                // Persisted after every page, not at the end: a run killed
                // mid-way must resume from where it got to rather than
                // re-fetching everything from a rate-limited endpoint.
                $source->setResumeCursor($cursor);

                if ($result->isComplete()) {
                    break;
                }

                // Politeness delay. Skipped when a page came back empty, since
                // there is nothing to be polite about.
                if ($result->records !== []) {
                    usleep($delayMicroseconds);
                }
            }
        } catch (Throwable $e) {
            // The cursor is left where it is, so the next run resumes rather
            // than starting over.
            return $this->fail($batch, $e->getMessage(), $found, $accepted);
        }

        $source->forceFill(['last_run_at' => CarbonImmutable::now()])->save();

        $batch->forceFill([
            'row_count' => $found,
            'accepted_count' => $accepted,
            'rejected_count' => $found - $accepted,
            'status' => IngestionBatch::STATUS_COMPLETED,
            'error_report' => $warnings === [] ? null : [
                'rows' => array_map(
                    static fn (string $w): array => ['row' => 0, 'column' => null, 'message' => $w],
                    array_slice($warnings, 0, 200),
                ),
            ],
            'finished_at' => CarbonImmutable::now(),
        ])->save();

        return $batch;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @param  Collection<string, int>  $locations
     */
    private function store(IngestionBatch $batch, Source $source, array $records, $locations): int
    {
        if ($records === []) {
            return 0;
        }

        $rows = [];
        $now = CarbonImmutable::now();

        foreach ($records as $record) {
            $locationId = $locations->get(mb_strtolower((string) $record['location'], 'UTF-8'));

            // A record naming a place this deployment does not track is not an
            // error — open datasets cover far more than one country's basket.
            if ($locationId === null) {
                continue;
            }

            $observedAt = $this->parseDate($record['observed_at'] ?? null) ?? $now;

            $rows[] = [
                'id' => Str::uuid()->toString(),
                'country_id' => $source->country_id,
                'location_id' => $locationId,
                'reporter_id' => null,
                'source_id' => $source->id,
                'ingestion_batch_id' => $batch->id,
                'raw_text' => (string) $record['item_text'],
                'raw_price' => (float) $record['price'],
                'currency_code' => mb_strtoupper((string) ($record['currency'] ?? $source->country->currency_code)),
                'raw_unit' => $record['unit'] ?? null,
                'raw_quantity' => $record['quantity'] ?? null,
                'photo_path' => null,
                'observed_at' => $observedAt,
                'collected_at' => $observedAt,
                'ingested_at' => $now,
                'device_metadata' => json_encode([
                    'source' => 'scraper',
                    'scraper' => $source->config['scraper'] ?? null,
                    'external_id' => $record['external_id'],
                ]),
                // Deterministic from the source and the record's natural key, so
                // a re-run or an overlapping resume collides on the unique index
                // rather than inserting a second copy.
                'client_idempotency_key' => Uuid::uuid5(
                    Uuid::NAMESPACE_URL,
                    'qeema:scrape:'.$source->slug.':'.$record['external_id'],
                )->toString(),
                'status' => Submission::STATUS_PENDING,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        // Scraped submissions have no reporter, so the (reporter_id,
        // idempotency_key) unique index does not apply — NULLs never collide in
        // Postgres. Deduplicating explicitly against what is already stored is
        // therefore the mechanism, not a belt-and-braces extra.
        $existing = DB::table('submissions')
            ->where('source_id', $source->id)
            ->whereIn('client_idempotency_key', array_column($rows, 'client_idempotency_key'))
            ->pluck('client_idempotency_key')
            ->all();

        $fresh = array_values(array_filter(
            $rows,
            static fn (array $r): bool => ! in_array($r['client_idempotency_key'], $existing, true),
        ));

        foreach (array_chunk($fresh, 500) as $chunk) {
            DB::table('submissions')->insert($chunk);
        }

        return count($fresh);
    }

    /**
     * @return Collection<string, int>
     */
    private function locationIndex(Source $source)
    {
        $index = collect();

        Location::query()
            ->where('country_id', $source->country_id)
            ->get(['id', 'slug', 'name', 'name_local'])
            ->each(function (Location $location) use ($index): void {
                foreach (array_filter([$location->slug, $location->name, $location->name_local]) as $name) {
                    $index->put(mb_strtolower((string) $name, 'UTF-8'), $location->id);
                }
            });

        return $index;
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function fail(IngestionBatch $batch, string $message, int $found = 0, int $accepted = 0): IngestionBatch
    {
        $batch->forceFill([
            'row_count' => $found,
            'accepted_count' => $accepted,
            'rejected_count' => max(0, $found - $accepted),
            'status' => IngestionBatch::STATUS_FAILED,
            'error_report' => ['fatal' => $message, 'rows' => []],
            'finished_at' => CarbonImmutable::now(),
        ])->save();

        return $batch;
    }
}

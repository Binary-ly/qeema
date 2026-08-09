<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Ingestion;

use App\Models\Country;
use App\Models\IngestionBatch;
use App\Models\Location;
use App\Models\Source;
use App\Models\Submission;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Imports a partner spreadsheet into submissions.
 *
 * Two properties define this class.
 *
 * **Partial success is the normal outcome.** Good rows land, bad rows come back
 * as a per-row report. A malformed file produces an actionable list, never a
 * 500 and never a wholesale rejection.
 *
 * **Re-uploading the same file is a no-op.** Partners resend files — after an
 * email thread, after a timeout, because someone was not sure it worked. The
 * file checksum and a per-row idempotency key mean that is harmless rather than
 * a doubling of every price in it.
 */
final class PartnerFileImporter
{
    private const INSERT_CHUNK = 500;

    public function __construct(
        private readonly SpreadsheetReader $reader = new SpreadsheetReader,
    ) {}

    /**
     * Offer a column mapping for a file without importing it.
     *
     * @return array{headers: list<string>, mapping: ColumnMapping, sample: list<array<string, string|null>>}
     */
    public function inspect(string $path): array
    {
        $headers = $this->reader->headers($path);
        $sample = [];

        foreach ($this->reader->rows($path) as $row) {
            $sample[] = $row;

            if (count($sample) >= 5) {
                break;
            }
        }

        return [
            'headers' => $headers,
            'mapping' => ColumnMapping::guess($headers),
            'sample' => $sample,
        ];
    }

    public function import(
        Source $source,
        string $path,
        ColumnMapping $mapping,
        ?string $originalFilename = null,
        ?int $userId = null,
    ): IngestionBatch {
        $country = $source->country;

        // Checked before hashing: hash_file() on a missing path raises before
        // a batch exists to record the failure on, which would surface as a
        // 500 instead of the actionable report this phase promises.
        if (! is_file($path)) {
            return $this->fail(
                IngestionBatch::query()->create([
                    'source_id' => $source->id,
                    'uploaded_by_user_id' => $userId,
                    'filename' => $originalFilename ?? basename($path),
                    'status' => IngestionBatch::STATUS_PROCESSING,
                    'column_mapping' => $mapping->toArray(),
                    'started_at' => CarbonImmutable::now(),
                ]),
                "Upload not found at {$path}.",
            );
        }

        $checksum = hash_file('sha256', $path) ?: Str::uuid()->toString();

        $existing = IngestionBatch::query()
            ->where('source_id', $source->id)
            ->where('checksum', $checksum)
            ->first();

        // Recognised rather than reprocessed. Returning the original batch means
        // the operator sees the previous result instead of a confusing success
        // that changed nothing.
        if ($existing !== null) {
            return $existing;
        }

        $batch = IngestionBatch::query()->create([
            'source_id' => $source->id,
            'uploaded_by_user_id' => $userId,
            'filename' => $originalFilename ?? basename($path),
            'checksum' => $checksum,
            'status' => IngestionBatch::STATUS_PROCESSING,
            'column_mapping' => $mapping->toArray(),
            'started_at' => CarbonImmutable::now(),
        ]);

        if (! $mapping->isComplete()) {
            return $this->fail($batch, sprintf(
                'Column mapping is incomplete: %s not mapped.',
                implode(', ', $mapping->missingRequired()),
            ));
        }

        try {
            return $this->process($batch, $source, $country, $path, $mapping, $checksum);
        } catch (Throwable $e) {
            // A file so malformed the reader itself failed. Recorded on the
            // batch rather than surfaced as an exception, so the operator gets
            // a message they can forward to the partner.
            return $this->fail($batch, $e->getMessage());
        }
    }

    private function process(
        IngestionBatch $batch,
        Source $source,
        Country $country,
        string $path,
        ColumnMapping $mapping,
        string $checksum,
    ): IngestionBatch {
        $validator = new RowValidator(
            country: $country,
            locationIdsBySlug: Location::query()
                ->where('country_id', $country->id)
                ->pluck('id', 'slug'),
            locationSlugsByName: $this->locationNameIndex($country),
            unitCodes: Unit::query()
                ->where('country_id', $country->id)
                ->pluck('code')
                ->map(fn (string $c): string => mb_strtolower($c))
                ->values()
                ->all(),
        );

        $rows = 0;
        $accepted = 0;
        $errors = [];
        $pending = [];

        foreach ($this->reader->rows($path) as $rowNumber => $row) {
            $rows++;

            $result = $validator->validate($rowNumber, $row, $mapping);

            if (! $result->isValid) {
                // Bounded: a file where every row is wrong should not produce a
                // 250,000-entry report nobody can read.
                if (count($errors) < 500) {
                    $errors = [...$errors, ...$result->errors];
                }

                continue;
            }

            /** @var array<string, mixed> $data */
            $data = $result->data;
            $pending[] = $this->toSubmission($batch, $source, $country, $data, $checksum, $rowNumber);
            $accepted++;

            if (count($pending) >= self::INSERT_CHUNK) {
                $this->insert($pending);
                $pending = [];
            }
        }

        $this->insert($pending);

        $batch->forceFill([
            'row_count' => $rows,
            'accepted_count' => $accepted,
            'rejected_count' => $rows - $accepted,
            'status' => IngestionBatch::STATUS_COMPLETED,
            'error_report' => $errors === [] ? null : [
                'rows' => $errors,
                'truncated' => count($errors) >= 500,
            ],
            'finished_at' => CarbonImmutable::now(),
        ])->save();

        return $batch;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function toSubmission(
        IngestionBatch $batch,
        Source $source,
        Country $country,
        array $data,
        string $checksum,
        int $rowNumber,
    ): array {
        /** @var CarbonImmutable $observedAt */
        $observedAt = $data['observed_at'];
        $now = CarbonImmutable::now();

        return [
            'id' => Str::uuid()->toString(),
            'country_id' => $country->id,
            'location_id' => $data['location_id'],
            'reporter_id' => null,
            'source_id' => $source->id,
            'ingestion_batch_id' => $batch->id,
            'raw_text' => $data['item_text'],
            'raw_price' => $data['price'],
            'currency_code' => $data['currency'],
            'raw_unit' => $data['unit'],
            'raw_quantity' => $data['quantity'],
            'photo_path' => null,
            'observed_at' => $observedAt,
            'collected_at' => $observedAt,
            'ingested_at' => $now,
            'device_metadata' => json_encode([
                'source' => 'partner_upload',
                'batch' => $batch->id,
                'row' => $rowNumber,
                'external_id' => $data['external_id'],
            ]),
            // Deterministic (UUID v5) from the file checksum and row number, so
            // the same row of the same file always yields the same key. That is
            // what makes a partner resending a file harmless even if the batch
            // checksum check is ever bypassed — a random UUID here would look
            // identical in code review and silently lose the property.
            'client_idempotency_key' => Uuid::uuid5(
                Uuid::NAMESPACE_URL,
                "qeema:batch:{$checksum}:row:{$rowNumber}",
            )->toString(),
            'status' => Submission::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Index locations by every name a partner might use.
     *
     * @return Collection<string, string>
     */
    private function locationNameIndex(Country $country): Collection
    {
        $index = collect();

        Location::query()
            ->where('country_id', $country->id)
            ->get(['slug', 'name', 'name_local'])
            ->each(function (Location $location) use ($index): void {
                foreach (array_filter([$location->name, $location->name_local]) as $name) {
                    $index->put(mb_strtolower((string) $name, 'UTF-8'), $location->slug);
                }
            });

        return $index;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insert(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        DB::table('submissions')->insert($rows);
    }

    private function fail(IngestionBatch $batch, string $message): IngestionBatch
    {
        $batch->forceFill([
            'status' => IngestionBatch::STATUS_FAILED,
            'error_report' => ['fatal' => $message, 'rows' => []],
            'finished_at' => CarbonImmutable::now(),
        ])->save();

        return $batch;
    }
}

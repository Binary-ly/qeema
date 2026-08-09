<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Scraping;

use App\Models\Source;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Worked example scraper: a published open-data CSV.
 *
 * The deliberate choice here is *what* it scrapes. Pointing an example scraper
 * at a shop's website would be a poor thing to ship — it invites operators to
 * take data nobody offered them, and gets the deployment's IP blocked. This one
 * targets datasets that are explicitly published for reuse under an open
 * licence, such as WFP's food price series on the Humanitarian Data Exchange
 * (CC-BY-IGO). That is data somebody deliberately made available.
 *
 * It is generic rather than site-specific: URL, column mapping and licence come
 * from `sources.config`, so adding a second open dataset is configuration
 * rather than a new class (constraint C3).
 *
 * Resumable via a row-offset cursor, rate limited, and it checks robots.txt
 * before fetching — a scraper that ignores robots is both rude and a good way
 * to lose the source permanently.
 */
final class OpenDataCsvScraper implements PriceScraper
{
    /** Rows per page. Small enough that an interrupted run loses little work. */
    private const PAGE_SIZE = 500;

    public function key(): string
    {
        return 'open_data_csv';
    }

    public function description(): string
    {
        return 'Fetches an openly-licensed CSV dataset and maps its columns onto price observations.';
    }

    public function requestsPerMinute(): int
    {
        return 10;
    }

    public function fetch(Source $source, ?string $cursor): ScrapeResult
    {
        /** @var array<string, mixed> $config */
        $config = $source->config ?? [];

        $url = $config['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new RuntimeException("Source '{$source->slug}' has no scraper URL configured.");
        }

        if (! $this->isAllowedByRobots($url)) {
            return new ScrapeResult(
                records: [],
                nextCursor: null,
                warnings: ["robots.txt at {$url} disallows fetching; skipping this source."],
            );
        }

        $offset = (int) ($cursor ?? 0);

        $body = $this->download($url);
        $rows = $this->parseCsv($body);

        if ($rows === []) {
            return ScrapeResult::empty();
        }

        $header = array_shift($rows);
        $page = array_slice($rows, $offset, self::PAGE_SIZE);

        /** @var array<string, string> $columns */
        $columns = $config['columns'] ?? [];

        $records = [];
        $warnings = [];

        foreach ($page as $index => $row) {
            $assoc = $this->combine($header, $row);
            $record = $this->toRecord($assoc, $columns, $source, $offset + $index);

            if ($record === null) {
                // Skipped rather than failed: an open dataset routinely carries
                // aggregate rows, footnotes and blanks, and refusing the whole
                // file over them would make the scraper useless.
                $warnings[] = 'Row '.($offset + $index + 2).' skipped: missing item, price or location.';

                continue;
            }

            $records[] = $record;
        }

        $consumed = $offset + count($page);
        $nextCursor = $consumed < count($rows) ? (string) $consumed : null;

        return new ScrapeResult(
            records: $records,
            nextCursor: $nextCursor,
            // Bounded so a badly-shaped dataset cannot produce a warning list
            // larger than the data.
            warnings: array_slice($warnings, 0, 50),
        );
    }

    private function download(string $url): string
    {
        try {
            $response = Http::withHeaders([
                // Identify the client honestly, with a contact route. An
                // anonymous scraper is what gets blocked.
                'User-Agent' => 'Qeema/'.config('qeema.version').' (+https://github.com/qeema/qeema; open affordability index)',
                'Accept' => 'text/csv, text/plain',
            ])
                ->timeout(60)
                ->connectTimeout(10)
                ->retry(2, 2000, throw: false)
                ->get($url);
        } catch (ConnectionException $e) {
            throw new RuntimeException("Could not reach {$url}: ".$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Fetching {$url} returned HTTP {$response->status()}.");
        }

        return $response->body();
    }

    /**
     * Check robots.txt for the fetch path.
     *
     * A deliberately conservative reading: any Disallow matching the path under
     * a wildcard or our own agent blocks the fetch. Being over-cautious costs a
     * data source; being under-cautious costs the deployment's reputation.
     */
    private function isAllowedByRobots(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $robotsUrl = $parts['scheme'].'://'.$parts['host'].'/robots.txt';
        $path = $parts['path'] ?? '/';

        try {
            $response = Http::timeout(10)->get($robotsUrl);
        } catch (ConnectionException) {
            // Unreachable robots.txt is treated as permissive: the alternative
            // is that one flaky request permanently disables a legitimate,
            // openly-licensed source.
            return true;
        }

        if (! $response->successful()) {
            return true;
        }

        $applies = false;

        foreach (preg_split('/\R/', $response->body()) ?: [] as $line) {
            $line = trim(preg_replace('/#.*$/', '', $line) ?? '');

            if ($line === '') {
                continue;
            }

            if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m) === 1) {
                $agent = strtolower(trim($m[1]));
                $applies = $agent === '*' || str_contains($agent, 'qeema');

                continue;
            }

            if (! $applies) {
                continue;
            }

            if (preg_match('/^disallow:\s*(.*)$/i', $line, $m) === 1) {
                $rule = trim($m[1]);

                if ($rule === '') {
                    continue;
                }

                if ($rule === '/' || str_starts_with($path, $rule)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return list<list<string>>
     */
    private function parseCsv(string $body): array
    {
        $rows = [];
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return [];
        }

        fwrite($handle, $body);
        rewind($handle);

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $rows[] = array_map(static fn ($c): string => trim((string) $c), $row);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<string>  $header
     * @param  list<string>  $row
     * @return array<string, string>
     */
    private function combine(array $header, array $row): array
    {
        $assoc = [];

        foreach ($header as $i => $name) {
            $assoc[$name] = $row[$i] ?? '';
        }

        return $assoc;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, string>  $columns
     * @return array<string, mixed>|null
     */
    private function toRecord(array $row, array $columns, Source $source, int $index): ?array
    {
        $get = static function (string $field) use ($row, $columns): ?string {
            $column = $columns[$field] ?? null;

            if ($column === null) {
                return null;
            }

            $value = $row[$column] ?? '';

            return trim($value) === '' ? null : trim($value);
        };

        $item = $get('item');
        $priceRaw = $get('price');
        $location = $get('location');

        if ($item === null || $priceRaw === null || $location === null) {
            return null;
        }

        $price = (float) preg_replace('/[^\d.\-]/', '', $priceRaw);

        if ($price <= 0.0) {
            return null;
        }

        // A stable natural key so re-running cannot double-count. Derived from
        // the dataset's own identifier where it has one, and from the row's
        // content otherwise.
        $externalId = $get('external_id')
            ?? substr(hash('sha256', $source->slug.'|'.$item.'|'.$location.'|'.($get('observed_at') ?? (string) $index)), 0, 32);

        return [
            'external_id' => $externalId,
            'item_text' => $item,
            'price' => $price,
            'location' => $location,
            'unit' => $get('unit'),
            'quantity' => $get('quantity') !== null ? (float) $get('quantity') : null,
            'currency' => $get('currency'),
            'observed_at' => $get('observed_at'),
        ];
    }
}

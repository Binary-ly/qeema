<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Country;
use App\Models\IngestionBatch;
use App\Models\Source;
use App\Models\Submission;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;
use App\Support\Scraping\OpenDataCsvScraper;
use App\Support\Scraping\PriceScraper;
use App\Support\Scraping\ScrapeResult;
use App\Support\Scraping\ScraperRegistry;
use App\Support\Scraping\ScraperRunner;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Scraper framework
|--------------------------------------------------------------------------
|
| Scrapers pull from someone else's server, which owes us nothing. The three
| properties tested here — idempotent, resumable, polite — are what keep a
| deployment from either corrupting its own index or getting itself blocked.
|
*/

beforeEach(function () {
    (new CountryConfigImporter)->import(
        (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'))
    );

    $this->country = Country::query()->where('code', 'LY')->firstOrFail();

    $this->source = Source::query()->create([
        'country_id' => $this->country->id,
        'type' => Source::TYPE_SCRAPER,
        'name' => 'Open price dataset',
        'slug' => 'open-price-dataset',
        'url' => 'https://data.example.org/prices.csv',
        'license' => 'CC-BY-4.0',
        'is_active' => true,
        'config' => [
            'scraper' => 'open_data_csv',
            'url' => 'https://data.example.org/prices.csv',
            'columns' => [
                'item' => 'commodity',
                'price' => 'price',
                'location' => 'market',
                'unit' => 'unit',
                'currency' => 'currency',
                'observed_at' => 'date',
            ],
        ],
    ]);
});

function datasetCsv(int $rows = 3): string
{
    $lines = ['commodity,price,market,unit,currency,date'];

    for ($i = 0; $i < $rows; $i++) {
        $market = $i % 2 === 0 ? 'Tripoli' : 'Benghazi';
        $lines[] = "Rice {$i},".(5 + $i).",{$market},kg,LYD,2026-03-01";
    }

    return implode("\n", $lines)."\n";
}

function fakeDataset(string $csv, string $robots = "User-agent: *\nAllow: /\n"): void
{
    Http::fake([
        'data.example.org/robots.txt' => Http::response($robots, 200),
        'data.example.org/prices.csv' => Http::response($csv, 200, ['Content-Type' => 'text/csv']),
    ]);
}

function runner(): ScraperRunner
{
    $registry = new ScraperRegistry;
    $registry->register(new OpenDataCsvScraper);

    return new ScraperRunner($registry);
}

describe('fetching an open dataset', function () {
    it('turns rows into submissions', function () {
        fakeDataset(datasetCsv(4));

        $batch = runner()->run($this->source);

        expect($batch->status)->toBe(IngestionBatch::STATUS_COMPLETED)
            ->and($batch->accepted_count)->toBe(4)
            ->and(Submission::query()->count())->toBe(4);
    });

    it('records the dataset text verbatim', function () {
        fakeDataset(datasetCsv(1));

        runner()->run($this->source);

        expect(Submission::query()->firstOrFail()->raw_text)->toBe('Rice 0');
    });

    it('links every submission to the scraper source', function () {
        fakeDataset(datasetCsv(2));

        runner()->run($this->source);

        $submission = Submission::query()->firstOrFail();

        expect($submission->source_id)->toBe($this->source->id)
            ->and($submission->reporter_id)->toBeNull()
            ->and($submission->device_metadata['source'])->toBe('scraper');
    });

    it('skips records naming a place this deployment does not track', function () {
        // Open datasets cover far more than one country's basket; a row for a
        // town we do not track is not an error.
        fakeDataset("commodity,price,market,unit,currency,date\nRice,6.50,Nairobi,kg,LYD,2026-03-01\nRice,6.50,Tripoli,kg,LYD,2026-03-01\n");

        $batch = runner()->run($this->source);

        expect($batch->accepted_count)->toBe(1);
    });

    it('warns about rows missing a required field rather than failing', function () {
        fakeDataset("commodity,price,market,unit,currency,date\n,6.50,Tripoli,kg,LYD,2026-03-01\nRice,6.50,Tripoli,kg,LYD,2026-03-01\n");

        $batch = runner()->run($this->source);

        expect($batch->status)->toBe(IngestionBatch::STATUS_COMPLETED)
            ->and($batch->accepted_count)->toBe(1)
            ->and($batch->errorRows())->not->toBeEmpty();
    });
});

describe('idempotency', function () {
    it('does not double-count when the same dataset is scraped twice', function () {
        // The property that keeps a scheduled scraper from inflating the index
        // a little more every night.
        fakeDataset(datasetCsv(3));

        runner()->run($this->source);
        $this->source->setResumeCursor(null);
        runner()->run($this->source->fresh());

        expect(Submission::query()->count())->toBe(3);
    });

    it('reports zero accepted on a re-run that found nothing new', function () {
        fakeDataset(datasetCsv(3));

        runner()->run($this->source);
        $this->source->setResumeCursor(null);
        $second = runner()->run($this->source->fresh());

        expect($second->accepted_count)->toBe(0)
            ->and($second->status)->toBe(IngestionBatch::STATUS_COMPLETED);
    });

    it('derives a stable key from the source and record identity', function () {
        fakeDataset(datasetCsv(1));

        runner()->run($this->source);
        $keyA = Submission::query()->firstOrFail()->client_idempotency_key;

        Submission::query()->delete();
        $this->source->setResumeCursor(null);
        runner()->run($this->source->fresh());

        expect(Submission::query()->firstOrFail()->client_idempotency_key)->toBe($keyA);
    });
});

describe('resumability', function () {
    it('persists a cursor so an interrupted run continues', function () {
        // Restarting against a rate-limited endpoint wastes the one thing a
        // scraper cannot get more of: requests.
        $scraper = new class implements PriceScraper
        {
            public int $calls = 0;

            public function key(): string
            {
                return 'paged_test';
            }

            public function description(): string
            {
                return 'test';
            }

            public function requestsPerMinute(): int
            {
                return 6000;
            }

            public function fetch(Source $source, ?string $cursor): ScrapeResult
            {
                $this->calls++;
                $page = (int) ($cursor ?? 0);

                if ($page >= 3) {
                    return ScrapeResult::empty();
                }

                return new ScrapeResult(
                    records: [[
                        'external_id' => "rec-{$page}",
                        'item_text' => "Item {$page}",
                        'price' => 10.0 + $page,
                        'location' => 'Tripoli',
                    ]],
                    nextCursor: (string) ($page + 1),
                );
            }
        };

        $registry = new ScraperRegistry;
        $registry->register($scraper);

        $this->source->forceFill(['config' => [...$this->source->config, 'scraper' => 'paged_test']])->save();

        $batch = (new ScraperRunner($registry))->run($this->source);

        expect($batch->accepted_count)->toBe(3)
            ->and($this->source->fresh()->resumeCursor())->toBeNull();
    });

    it('leaves the cursor in place when a run fails mid-way', function () {
        $scraper = new class implements PriceScraper
        {
            public function key(): string
            {
                return 'failing_test';
            }

            public function description(): string
            {
                return 'test';
            }

            public function requestsPerMinute(): int
            {
                return 6000;
            }

            public function fetch(Source $source, ?string $cursor): ScrapeResult
            {
                if ($cursor === '1') {
                    throw new RuntimeException('remote end went away');
                }

                return new ScrapeResult(
                    records: [[
                        'external_id' => 'rec-0',
                        'item_text' => 'Item 0',
                        'price' => 10.0,
                        'location' => 'Tripoli',
                    ]],
                    nextCursor: '1',
                );
            }
        };

        $registry = new ScraperRegistry;
        $registry->register($scraper);
        $this->source->forceFill(['config' => [...$this->source->config, 'scraper' => 'failing_test']])->save();

        $batch = (new ScraperRunner($registry))->run($this->source);

        expect($batch->status)->toBe(IngestionBatch::STATUS_FAILED)
            ->and($batch->error_report['fatal'])->toContain('remote end went away')
            // The next run picks up where this one stopped rather than
            // re-fetching everything.
            ->and($this->source->fresh()->resumeCursor())->toBe('1');
    });
});

describe('politeness', function () {
    it('checks robots.txt before fetching', function () {
        fakeDataset(datasetCsv(3), robots: "User-agent: *\nDisallow: /\n");

        $batch = runner()->run($this->source);

        expect(Submission::query()->count())->toBe(0)
            ->and($batch->errorRows()[0]['message'])->toContain('robots.txt');
    });

    it('honours a disallow that matches the path', function () {
        fakeDataset(datasetCsv(3), robots: "User-agent: *\nDisallow: /prices\n");

        runner()->run($this->source);

        expect(Submission::query()->count())->toBe(0);
    });

    it('proceeds when robots.txt allows the path', function () {
        fakeDataset(datasetCsv(2), robots: "User-agent: *\nDisallow: /private/\n");

        runner()->run($this->source);

        expect(Submission::query()->count())->toBe(2);
    });

    it('honours a wildcard disallow on a file extension', function () {
        // The exact rule published by the Humanitarian Data Exchange, which is
        // the source this scraper's own documentation names. A path ending
        // `.csv` does not *start* with `/*.csv$`, so a prefix comparison read
        // every tabular file on that site as allowed and the scraper fetched
        // what it had been told not to.
        fakeDataset(datasetCsv(3), robots: "User-agent: *\nDisallow: /*.csv$\n");

        runner()->run($this->source);

        expect(Submission::query()->count())->toBe(0);
    });

    it('honours a wildcard in the middle of a rule', function () {
        fakeDataset(datasetCsv(3), robots: "User-agent: *\nDisallow: /*.csv\n");

        runner()->run($this->source);

        expect(Submission::query()->count())->toBe(0);
    });

    it('treats a trailing dollar as an end anchor rather than a prefix', function () {
        // `/prices$` matches a path that IS `/prices`, not one that merely
        // begins with it. Reading the anchor as decoration would refuse a
        // legitimate source, which costs data for no protection.
        fakeDataset(datasetCsv(2), robots: "User-agent: *\nDisallow: /prices$\n");

        runner()->run($this->source);

        expect(Submission::query()->count())->toBe(2);
    });

    it('does not let a wildcard rule match every path', function () {
        // `/dataset/*/history` is a real HDX rule. If wildcard handling were
        // written as "contains a star, therefore blocked", this source would be
        // refused too — over-caution is not free.
        fakeDataset(datasetCsv(2), robots: "User-agent: *\nDisallow: /dataset/*/history\n");

        runner()->run($this->source);

        expect(Submission::query()->count())->toBe(2);
    });

    it('proceeds when robots.txt is unreachable', function () {
        // One flaky request must not permanently disable a legitimate,
        // openly-licensed source.
        Http::fake([
            'data.example.org/robots.txt' => Http::response('', 404),
            'data.example.org/prices.csv' => Http::response(datasetCsv(2), 200),
        ]);

        runner()->run($this->source);

        expect(Submission::query()->count())->toBe(2);
    });

    it('identifies itself honestly in the user agent', function () {
        // An anonymous scraper is what gets blocked.
        fakeDataset(datasetCsv(1));

        runner()->run($this->source);

        Http::assertSent(fn ($request) => str_contains($request->header('User-Agent')[0] ?? '', 'Qeema'));
    });

    it('declares a conservative request rate', function () {
        expect((new OpenDataCsvScraper)->requestsPerMinute())->toBeLessThanOrEqual(30);
    });
});

describe('failure handling', function () {
    it('fails the batch, not the request, when the source names an unknown scraper', function () {
        $this->source->forceFill(['config' => ['scraper' => 'does_not_exist']])->save();

        $batch = runner()->run($this->source);

        expect($batch->status)->toBe(IngestionBatch::STATUS_FAILED)
            ->and($batch->error_report['fatal'])->toContain('does_not_exist');
    });

    it('fails the batch when the remote returns an error status', function () {
        Http::fake([
            'data.example.org/robots.txt' => Http::response("User-agent: *\nAllow: /\n", 200),
            'data.example.org/prices.csv' => Http::response('upstream exploded', 503),
        ]);

        $batch = runner()->run($this->source);

        expect($batch->status)->toBe(IngestionBatch::STATUS_FAILED)
            ->and($batch->error_report['fatal'])->toContain('503');
    });
});

describe('the registry', function () {
    it('registers the worked example by default', function () {
        expect(app(ScraperRegistry::class)->keys())->toContain('open_data_csv');
    });

    it('names the registered scrapers when asked for an unknown one', function () {
        expect(fn () => app(ScraperRegistry::class)->get('nope'))
            ->toThrow(RuntimeException::class, 'open_data_csv');
    });
});

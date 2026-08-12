<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Country;
use App\Models\Location;
use App\Models\Source;
use App\Models\Submission;
use App\Support\Scraping\ScraperRegistry;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Running the configured open-data sources
|--------------------------------------------------------------------------
|
| `ScraperRunner` was referenced only by its own tests. Pagination, rate
| limiting, robots.txt, resumable cursors and deterministic idempotency keys all
| worked, and nothing ran any of them — so a scraper source configured in the
| admin panel sat there being never fetched. The fifth component in this
| codebase found to be complete and unreachable.
|
| The property worth protecting hardest is the one about *not* fetching: a stock
| deployment configures no source, and this must therefore do nothing at all.
|
*/

beforeEach(function (): void {
    $this->country = Country::factory()->create(['is_active' => true]);
    $this->location = Location::factory()->create([
        'country_id' => $this->country->id,
        'name' => 'Northtown',
    ]);
});

function scraperSource(Country $country, array $config = []): Source
{
    return Source::query()->create([
        'country_id' => $country->id,
        'slug' => 'open-data',
        'name' => 'An open dataset',
        'type' => Source::TYPE_SCRAPER,
        'license' => 'CC-BY-4.0',
        'is_active' => true,
        'config' => array_merge([
            'scraper' => 'open_data_csv',
            'url' => 'https://data.example.org/prices.csv',
            'columns' => [
                'item' => 'Product',
                'price' => 'Price',
                'location' => 'Market',
            ],
        ], $config),
    ]);
}

it('does nothing on a deployment that has configured no source', function (): void {
    // The stock state. A platform that starts fetching third-party sites the
    // moment it is installed would be both rude and a breach of the constraint
    // that nothing leaves the network at runtime unless an operator asked.
    Http::fake();

    $this->artisan('qeema:scrape')
        ->expectsOutputToContain('No active scraper sources')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('ignores a source that has been switched off', function (): void {
    Http::fake();

    scraperSource($this->country)->forceFill(['is_active' => false])->save();

    $this->artisan('qeema:scrape')->assertSuccessful();

    Http::assertNothingSent();
});

it('turns a configured dataset into submissions', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('User-agent: *', 200),
        '*' => Http::response("Product,Price,Market\nRice,6.50,Northtown\nFlour,4.20,Northtown\n", 200),
    ]);

    scraperSource($this->country);

    $this->artisan('qeema:scrape')->assertSuccessful();

    expect(Submission::query()->count())->toBe(2);
});

it('leaves the pipeline to resolve what it fetched', function (): void {
    // Scraped rows are ordinary pending submissions. There is deliberately no
    // second path into the index: the sweeper adopts them and the same
    // resolution and screening applies as to a price typed by a reporter.
    Http::fake([
        '*/robots.txt' => Http::response('User-agent: *', 200),
        '*' => Http::response("Product,Price,Market\nRice,6.50,Northtown\n", 200),
    ]);

    scraperSource($this->country);

    $this->artisan('qeema:scrape')->assertSuccessful();

    expect(Submission::query()->first()->status)->toBe(Submission::STATUS_PENDING);
});

it('reports a source that has stopped working rather than failing silently', function (): void {
    // A moved URL or a changed column is an operator's problem to fix, and the
    // log line is the only way they learn of it — nothing else notices a source
    // that has quietly stopped producing.
    Http::fake([
        '*/robots.txt' => Http::response('User-agent: *', 200),
        '*' => Http::response('gone', 404),
    ]);

    scraperSource($this->country);

    $this->artisan('qeema:scrape')
        ->expectsOutputToContain('failed')
        ->assertSuccessful();
});

it('refuses a source naming a scraper that does not exist', function (): void {
    Http::fake();

    scraperSource($this->country, ['scraper' => 'not_a_real_scraper']);

    $this->artisan('qeema:scrape')
        ->expectsOutputToContain('failed')
        ->assertSuccessful();

    expect(app(ScraperRegistry::class)->has('not_a_real_scraper'))->toBeFalse();
});

it('can be pointed at a single source', function (): void {
    Http::fake([
        '*/robots.txt' => Http::response('User-agent: *', 200),
        '*' => Http::response("Product,Price,Market\nRice,6.50,Northtown\n", 200),
    ]);

    scraperSource($this->country);

    $this->artisan('qeema:scrape', ['--source' => 'a-source-that-does-not-exist'])
        ->expectsOutputToContain('No active scraper sources')
        ->assertSuccessful();

    expect(Submission::query()->count())->toBe(0);
});

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Country;
use App\Models\IngestionBatch;
use App\Models\Source;
use App\Models\Submission;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;

/*
|--------------------------------------------------------------------------
| qeema:import:file
|--------------------------------------------------------------------------
|
| The shell counterpart of the admin import page. It has to do exactly what
| the page does — guess a mapping, let the operator correct it, import with
| per-row errors — and it has to say what happened in words an operator can
| act on, because a `tinker` one-liner that said nothing is how a basket item
| went unimported for four months.
|
*/

beforeEach(function () {
    (new CountryConfigImporter)->import(
        (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'))
    );
    $this->country = Country::query()->where('code', 'LY')->firstOrFail();
    $this->source = Source::query()
        ->where('country_id', $this->country->id)
        ->where('type', Source::TYPE_PARTNER_UPLOAD)
        ->firstOrFail();
});

/** Write a CSV to a temp file and return its path. */
function importCommandCsv(string $contents): string
{
    $path = sys_get_temp_dir().'/qeema-import-cmd-'.bin2hex(random_bytes(6)).'.csv';
    file_put_contents($path, $contents);

    return $path;
}

it('imports a file and reports accepted and rejected rows', function () {
    $path = importCommandCsv(
        "Product,Price,Market,Unit,Date\n"
        ."Rice,6.50,Tripoli,kg,2026-03-01\n"
        ."Flour,4.20,Nowhere,kg,2026-03-01\n"
    );

    $this->artisan('qeema:import:file', ['path' => $path, '--source' => $this->source->slug])
        ->expectsOutputToContain('1 accepted, 1 rejected')
        ->expectsOutputToContain('Nowhere')
        ->assertExitCode(0);

    expect(Submission::query()->count())->toBe(1)
        ->and(IngestionBatch::query()->sole()->filename)->toBe(basename($path));
});

it('refuses a source it does not know and lists the ones it does', function () {
    $path = importCommandCsv("Product,Price,Market\nRice,6.50,Tripoli\n");

    $this->artisan('qeema:import:file', ['path' => $path, '--source' => 'no-such-source'])
        ->expectsOutputToContain('no-such-source')
        ->expectsOutputToContain($this->source->slug)
        ->assertExitCode(1);

    expect(Submission::query()->count())->toBe(0);
});

it('fails on a column it could not guess, and takes the override', function () {
    $path = importCommandCsv("Thing,Price,Market\nRice,6.50,Tripoli\n");

    $this->artisan('qeema:import:file', ['path' => $path, '--source' => $this->source->slug])
        ->expectsOutputToContain('Not mapped: item')
        ->assertExitCode(1);

    $this->artisan('qeema:import:file', [
        'path' => $path,
        '--source' => $this->source->slug,
        '--map' => ['item=Thing'],
    ])->assertExitCode(0);

    expect(Submission::query()->sole()->raw_text)->toBe('Rice');
});

it('rejects an override naming a column the file does not have', function () {
    $path = importCommandCsv("Product,Price,Market\nRice,6.50,Tripoli\n");

    $this->artisan('qeema:import:file', [
        'path' => $path,
        '--source' => $this->source->slug,
        '--map' => ['unit=Measure'],
    ])
        ->expectsOutputToContain('"Measure" is not in the file')
        ->assertExitCode(1);

    expect(IngestionBatch::query()->count())->toBe(0);
});

it('writes nothing on a dry run', function () {
    $path = importCommandCsv("Product,Price,Market\nRice,6.50,Tripoli\n");

    $this->artisan('qeema:import:file', [
        'path' => $path,
        '--source' => $this->source->slug,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry run')
        ->assertExitCode(0);

    expect(Submission::query()->count())->toBe(0)
        ->and(IngestionBatch::query()->count())->toBe(0);
});

it('recognises a file it has already imported instead of doubling it', function () {
    $path = importCommandCsv("Product,Price,Market\nRice,6.50,Tripoli\n");
    $args = ['path' => $path, '--source' => $this->source->slug];

    $this->artisan('qeema:import:file', $args)->assertExitCode(0);
    $this->artisan('qeema:import:file', $args)
        ->expectsOutputToContain('already imported')
        ->assertExitCode(0);

    expect(Submission::query()->count())->toBe(1)
        ->and(IngestionBatch::query()->count())->toBe(1);
});

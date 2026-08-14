<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Country;
use App\Models\PriceObservation;
use App\Models\Submission;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| qeema:demo:scale
|--------------------------------------------------------------------------
|
| Builds a large corpus-backed dataset for load and robustness testing. What is
| worth holding here is mostly what it REFUSES to do: it must not half-generate
| onto an existing dataset, because the generator inserts exchange rates without
| upserting and would abort partway, leaving a database nobody can reason about.
|
*/

it('requires a country', function (): void {
    $this->artisan('qeema:demo:scale')
        ->expectsOutputToContain('--country is required')
        ->assertFailed();
});

it('refuses a country that is not seeded', function (): void {
    $this->artisan('qeema:demo:scale', ['--country' => 'ZZ'])
        ->expectsOutputToContain('is not seeded')
        ->assertFailed();
});

it('refuses to generate on top of an existing dataset', function (): void {
    $this->artisan('qeema:config:import', ['--country' => 'ly'])->assertSuccessful();

    $country = Country::query()->where('code', 'LY')->firstOrFail();
    Submission::factory()->create(['country_id' => $country->id]);

    // Half-generating would abort on a duplicate exchange rate partway through.
    $this->artisan('qeema:demo:scale', ['--country' => 'LY'])
        ->expectsOutputToContain('already has submissions')
        ->assertFailed();
});

it('refuses a country with no corpus', function (): void {
    Country::factory()->create(['code' => 'QQ', 'is_active' => true]);

    $this->artisan('qeema:demo:scale', ['--country' => 'QQ'])
        ->expectsOutputToContain('No corpus')
        ->assertFailed();
});

it('generates a dataset whose text comes from the corpus', function (): void {
    $this->artisan('qeema:config:import', ['--country' => 'ly'])->assertSuccessful();

    $this->artisan('qeema:demo:scale', [
        '--country' => 'LY',
        '--days' => 3,
        '--reports-per-cell' => 2,
    ])->assertSuccessful();

    expect(PriceObservation::query()->count())->toBeGreaterThan(0);

    // The point of the exercise: the raw text must not simply be catalogue
    // names with mutations, which is what the shipped demo produces.
    $corpus = json_decode(
        (string) file_get_contents(base_path('../countries/corpus/ly.json')),
        true,
    )['items'];

    $wordings = [];

    foreach ($corpus as $phrasings) {
        foreach ($phrasings as $phrasing) {
            $wordings[$phrasing] = true;
        }
    }

    $texts = Submission::query()->whereNotNull('raw_text')->pluck('raw_text');
    $fromCorpus = $texts->filter(function (string $text) use ($wordings): bool {
        foreach (array_keys($wordings) as $wording) {
            if (str_contains($text, $wording)) {
                return true;
            }
        }

        return false;
    });

    expect($texts)->not->toBeEmpty()
        ->and($fromCorpus->count())->toBeGreaterThan((int) ($texts->count() * 0.4));
});

it('produces more observations per cell when asked for more reporters', function (): void {
    $this->artisan('qeema:config:import', ['--country' => 'ly'])->assertSuccessful();

    $this->artisan('qeema:demo:scale', [
        '--country' => 'LY',
        '--days' => 3,
        '--reports-per-cell' => 1,
    ])->assertSuccessful();

    $single = PriceObservation::query()->count();

    // A second country so the two runs do not collide on exchange rates.
    $this->artisan('qeema:config:import', ['--country' => 've'])->assertSuccessful();

    $this->artisan('qeema:demo:scale', [
        '--country' => 'VE',
        '--days' => 3,
        '--reports-per-cell' => 3,
    ])->assertSuccessful();

    $both = PriceObservation::query()->count();

    // VE has fewer items than LY, so this is deliberately a loose check: the
    // claim is that the knob does something substantial, not an exact ratio.
    expect($both - $single)->toBeGreaterThan($single);
});

it('produces submissions that have no right answer at all', function (): void {
    $this->artisan('qeema:config:import', ['--country' => 'ly'])->assertSuccessful();

    $this->artisan('qeema:demo:scale', [
        '--country' => 'LY',
        '--days' => 20,
        '--distractor-rate' => 0.9,
    ])->assertSuccessful();

    // Ground truth with a null item is the record that NO catalogue entry would
    // have been correct. Without these rows a dataset can only measure recall.
    $unmatchable = DB::table('qeema_eval.gt_submissions')->whereNull('true_canonical_item_id')->count();

    expect($unmatchable)->toBeGreaterThan(0);

    $ids = DB::table('qeema_eval.gt_submissions')
        ->whereNull('true_canonical_item_id')->pluck('submission_id');

    // They wait for a human and carry no resolution: nothing matched, which is a
    // different state from having matched badly.
    expect(Submission::query()->whereIn('id', $ids)->where('status', '!=', 'needs_review')->count())->toBe(0)
        ->and(DB::table('resolutions')->whereIn('submission_id', $ids)->count())->toBe(0)
        ->and(PriceObservation::query()->whereIn('submission_id', $ids)->count())->toBe(0);
});

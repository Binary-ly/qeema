<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Country;

/*
|--------------------------------------------------------------------------
| qeema:config:import
|--------------------------------------------------------------------------
|
| The supported way to apply an edit to a country file. Before this existed,
| editing countries/*.yaml on a running deployment did nothing: bootstrap seeds
| a country only when it is absent, so a basket revision could be written down
| and never applied.
|
*/

it('imports every configured country', function (): void {
    $this->artisan('qeema:config:import')->assertSuccessful();

    expect(Country::query()->count())->toBeGreaterThanOrEqual(2);
});

it('can be pointed at one country', function (): void {
    $this->artisan('qeema:config:import', ['--country' => 'ly'])
        ->expectsOutputToContain('LY:')
        ->assertSuccessful();

    expect(Country::query()->where('code', 'LY')->exists())->toBeTrue()
        ->and(Country::query()->where('code', 'VE')->exists())->toBeFalse();
});

it('fails on a country that is not configured', function (): void {
    // A typo must not look like success, or an operator believes a revision was
    // applied when nothing was read.
    $this->artisan('qeema:config:import', ['--country' => 'ZZ'])
        ->expectsOutputToContain('No configuration file matches')
        ->assertFailed();
});

it('writes nothing on a dry run', function (): void {
    $this->artisan('qeema:config:import', ['--dry-run' => true])
        ->expectsOutputToContain('nothing written')
        ->assertSuccessful();

    expect(Country::query()->count())->toBe(0);
});

it('is safe to run twice', function (): void {
    $this->artisan('qeema:config:import', ['--country' => 'ly'])->assertSuccessful();
    $before = Country::query()->where('code', 'LY')->firstOrFail();

    $this->artisan('qeema:config:import', ['--country' => 'ly'])->assertSuccessful();

    expect(Country::query()->where('code', 'LY')->count())->toBe(1)
        ->and(Country::query()->where('code', 'LY')->firstOrFail()->id)->toBe($before->id);
});

it('points the operator at the linking step', function (): void {
    // A revision that is imported but never anchored publishes a null level
    // everywhere, and this line is where an operator finds that out.
    $this->artisan('qeema:config:import', ['--country' => 'ly'])
        ->expectsOutputToContain('qeema:index:link --country=LY')
        ->assertSuccessful();
});

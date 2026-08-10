<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Basket;
use App\Models\Country;
use App\Models\IndexSnapshot;
use App\Models\Location;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Rolling the index forward
|--------------------------------------------------------------------------
|
| `qeema:index` only recomputes snapshots that already exist, which is right for
| corrections and useless for time passing: a deployment left alone would never
| publish a new calendar day. This command creates the ones that do not exist.
|
| The timezone tests are the substantive ones. There is no such thing as "today"
| on a country-agnostic platform, and a server-local date publishes tomorrow's
| figure early in one country and yesterday's late in another.
|
*/

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function countryInTimezone(string $timezone, string $code): Country
{
    $country = Country::factory()->create([
        'code' => $code,
        'timezone' => $timezone,
        'is_active' => true,
    ]);

    Location::factory()->create(['country_id' => $country->id, 'is_active' => true]);
    Basket::factory()->create(['country_id' => $country->id]);

    return $country;
}

it('publishes each country on its own calendar day', function (): void {
    // 22:30 UTC: already tomorrow well east of Greenwich, still today well west.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 22:30:00', 'UTC'));

    $ahead = countryInTimezone('Pacific/Kiritimati', 'XA');   // UTC+14
    $behind = countryInTimezone('Pacific/Honolulu', 'XB');    // UTC-10

    $this->artisan('qeema:index:publish', ['--days' => 0])->assertSuccessful();

    expect(snapshotDates($ahead))->toBe(['2026-08-11'])
        ->and(snapshotDates($behind))->toBe(['2026-08-10']);
});

function snapshotDates(Country $country): array
{
    return IndexSnapshot::query()
        ->where('country_id', $country->id)
        ->orderBy('snapshot_date')
        ->pluck('snapshot_date')
        ->map(fn ($date): string => CarbonImmutable::parse((string) $date)->toDateString())
        ->unique()
        ->values()
        ->all();
}

it('creates nothing the second time it runs', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC'));
    $country = countryInTimezone('Africa/Tripoli', 'XC');

    $this->artisan('qeema:index:publish', ['--days' => 0])->assertSuccessful();
    $first = IndexSnapshot::query()->firstOrFail();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 10:00:00', 'UTC'));
    $this->artisan('qeema:index:publish', ['--days' => 0])->assertSuccessful();

    // Recomputing an existing snapshot is the drain's job, and doing it here
    // would republish inside the window the drain leaves for screening.
    expect(IndexSnapshot::query()->count())->toBe(1)
        ->and($first->fresh()->computed_at->toIso8601String())
        ->toBe($first->computed_at->toIso8601String());

    expect($country->code)->toBe('XC');
});

it('recomputes on request', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC'));
    countryInTimezone('Africa/Tripoli', 'XD');

    $this->artisan('qeema:index:publish', ['--days' => 0])->assertSuccessful();
    $before = IndexSnapshot::query()->firstOrFail()->computed_at;

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 11:00:00', 'UTC'));
    $this->artisan('qeema:index:publish', ['--days' => 0, '--force' => true])->assertSuccessful();

    expect(IndexSnapshot::query()->count())->toBe(1)
        ->and(IndexSnapshot::query()->firstOrFail()->computed_at->greaterThan($before))->toBeTrue();
});

it('fills the days a quiet location never had a snapshot for', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC'));
    $country = countryInTimezone('Africa/Tripoli', 'XE');

    $this->artisan('qeema:index:publish', ['--days' => 3])->assertSuccessful();

    expect(snapshotDates($country))->toBe([
        '2026-08-07', '2026-08-08', '2026-08-09', '2026-08-10',
    ]);
});

it('leaves an inactive country alone', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC'));
    $country = countryInTimezone('Africa/Tripoli', 'XF');
    $country->forceFill(['is_active' => false])->save();

    $this->artisan('qeema:index:publish', ['--days' => 0])->assertSuccessful();

    expect(IndexSnapshot::query()->count())->toBe(0);
});

it('leaves an inactive location alone', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC'));
    $country = countryInTimezone('Africa/Tripoli', 'XG');
    Location::query()->where('country_id', $country->id)->update(['is_active' => false]);

    $this->artisan('qeema:index:publish', ['--days' => 0])->assertSuccessful();

    expect(IndexSnapshot::query()->count())->toBe(0);
});

it('can be restricted to one country', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC'));
    $wanted = countryInTimezone('Africa/Tripoli', 'XH');
    $other = countryInTimezone('America/Caracas', 'XI');

    $this->artisan('qeema:index:publish', ['--days' => 0, '--country' => 'XH'])->assertSuccessful();

    expect(snapshotDates($wanted))->toHaveCount(1)
        ->and(snapshotDates($other))->toBe([]);
});

it('fails loudly when asked for a country that is not there', function (): void {
    // An operator typo deserves a non-zero exit.
    $this->artisan('qeema:index:publish', ['--country' => 'ZZ'])->assertFailed();
});

it('succeeds quietly when there is simply nothing to publish', function (): void {
    // A deployment mid-setup. This runs hourly, and failing every hour would
    // train whoever reads the logs to stop reading them.
    $this->artisan('qeema:index:publish')
        ->expectsOutputToContain('No active countries')
        ->assertSuccessful();
});

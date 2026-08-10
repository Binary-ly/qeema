<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Country;
use App\Models\IndexSnapshot;
use App\Models\Location;
use App\Services\Index\IndexStaleness;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The grace window
|--------------------------------------------------------------------------
|
| An observation marks its snapshots stale inside the transaction that creates
| it. Anomaly screening happens a moment later, in the next job. A recompute
| landing in that gap publishes a figure containing a price nobody has screened
| and corrects it seconds afterwards — self-correcting, and briefly wrong in
| public, which is the one thing this platform must not be.
|
*/

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function staleSnapshot(?CarbonImmutable $markedAt): IndexSnapshot
{
    $country = Country::factory()->create();

    return IndexSnapshot::factory()->create([
        'country_id' => $country->id,
        'location_id' => Location::factory()->create(['country_id' => $country->id])->id,
        'is_stale' => true,
        'stale_marked_at' => $markedAt,
    ]);
}

it('holds a snapshot back until the grace window has passed', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC'));
    staleSnapshot(CarbonImmutable::parse('2026-08-10 11:59:30', 'UTC'));

    expect((new IndexStaleness)->pending(500, graceSeconds: 60))->toHaveCount(0);
});

it('recomputes once the window has passed', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC'));
    staleSnapshot(CarbonImmutable::parse('2026-08-10 11:58:00', 'UTC'));

    expect((new IndexStaleness)->pending(500, graceSeconds: 60))->toHaveCount(1);
});

it('treats a snapshot with no stamp as old enough', function (): void {
    // Rows that predate the column. Refusing to recompute because nobody
    // recorded when it went stale would be the wrong way round.
    staleSnapshot(null);

    expect((new IndexStaleness)->pending(500, graceSeconds: 3600))->toHaveCount(1);
});

it('stamps the moment a snapshot goes stale', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC'));

    $snapshot = staleSnapshot(null);
    $snapshot->forceFill(['is_stale' => false, 'stale_marked_at' => null])->save();

    (new IndexStaleness)->markRange(
        $snapshot->location_id,
        CarbonImmutable::parse($snapshot->snapshot_date->toDateString()),
        CarbonImmutable::parse($snapshot->snapshot_date->toDateString()),
    );

    expect($snapshot->fresh()->stale_marked_at->toIso8601String())
        ->toBe(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC')->toIso8601String());
});

it('keeps the first reason a snapshot went stale, not the latest', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC'));
    $snapshot = staleSnapshot(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC'));

    // A busy location receives observations continuously. If each one refreshed
    // the stamp, the snapshot would sit permanently inside the grace window and
    // never be republished at all.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:45', 'UTC'));

    (new IndexStaleness)->markRange(
        $snapshot->location_id,
        CarbonImmutable::parse($snapshot->snapshot_date->toDateString()),
        CarbonImmutable::parse($snapshot->snapshot_date->toDateString()),
    );

    expect($snapshot->fresh()->stale_marked_at->toIso8601String())
        ->toBe(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC')->toIso8601String());
});

it('reports the age of the oldest thing in the backlog', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC'));

    staleSnapshot(CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC'));
    staleSnapshot(CarbonImmutable::parse('2026-08-10 11:00:00', 'UTC'));

    // Backlog age, not backlog size: a hundred snapshots being worked through
    // is healthy, one stale since this morning is a stopped pipeline.
    expect((new IndexStaleness)->oldestStaleAt()?->toIso8601String())
        ->toBe(CarbonImmutable::parse('2026-08-10 09:00:00', 'UTC')->toIso8601String());
});

it('reports no backlog when nothing is stale', function (): void {
    expect((new IndexStaleness)->oldestStaleAt())->toBeNull();
});

it('drains only what is outside the window', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC'));

    staleSnapshot(CarbonImmutable::parse('2026-08-10 11:00:00', 'UTC'));
    staleSnapshot(CarbonImmutable::parse('2026-08-10 11:59:50', 'UTC'));

    $this->artisan('qeema:index', ['--grace' => 60])->assertSuccessful();

    expect(IndexSnapshot::query()->where('is_stale', true)->count())->toBe(1);
});

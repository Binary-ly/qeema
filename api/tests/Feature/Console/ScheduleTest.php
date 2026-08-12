<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
|--------------------------------------------------------------------------
| The clock itself
|--------------------------------------------------------------------------
|
| This is the regression guard for the failure that started this phase. Every
| stage of the pipeline was built and tested; there was simply no schedule, so
| corrections never reached published figures and no new calendar day was ever
| published. Nothing in the old suite could fail as a result, because the
| absence of a scheduled task is invisible to a test of the task.
|
| So the schedule is asserted directly. If someone removes a task, or quietly
| drops `onOneServer` and a second scheduler starts duplicating the drain, a
| build breaks rather than a deployment.
|
*/

/**
 * The scheduled task that runs exactly this command.
 *
 * Matched on a boundary rather than a substring: `qeema:index` is a prefix of
 * `qeema:index:publish`, and a substring match would silently assert the wrong
 * task's cadence depending on the order they happen to be registered in.
 */
function scheduled(string $signature): Event
{
    $pattern = '/'.preg_quote($signature, '/').'(?![\w:.\-])/';

    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => preg_match($pattern, (string) $event->command) === 1);

    expect($events)->toHaveCount(1, "Expected exactly one scheduled task running {$signature}.");

    return $events->first();
}

it('reconciles the pipeline every minute', function (): void {
    expect(scheduled('qeema:pipeline:sweep')->expression)->toBe('* * * * *');
});

it('drains stale snapshots every minute', function (): void {
    // Without this a correction to a price never reaches the published figure.
    expect(scheduled('qeema:index')->expression)->toBe('* * * * *');
});

it('rolls the index forward every hour', function (): void {
    // Without this no new calendar day is ever published.
    expect(scheduled('qeema:index:publish')->expression)->toBe('0 * * * *');
});

it('records a heartbeat every minute', function (): void {
    expect(scheduled('qeema:scheduler:heartbeat')->expression)->toBe('* * * * *');
});

it('checks its own health every five minutes', function (): void {
    // Without this, the platform's failures stay silent by construction:
    // nothing else in the system notices that it has stopped publishing.
    expect(scheduled('qeema:pipeline:health')->expression)->toBe('*/5 * * * *');
});

it('keeps queue metrics and prunes failed jobs', function (): void {
    expect(scheduled('horizon:snapshot')->expression)->toBe('*/5 * * * *')
        ->and(scheduled('queue:prune-failed')->expression)->toBe('0 0 * * *');
});

it('never lets two schedulers do the same work twice', function (): void {
    // A cluster with two scheduler containers would otherwise drain the same
    // backlog twice and dispatch every stranded submission twice over.
    foreach ([
        'qeema:pipeline:sweep',
        'qeema:index',
        'qeema:index:publish',
        'qeema:scheduler:heartbeat',
    ] as $signature) {
        expect(scheduled($signature)->onOneServer)->toBeTrue("{$signature} must be a cluster-wide singleton.");
    }
});

it('never lets a slow run stack on top of itself', function (): void {
    // A drain that occasionally takes longer than a minute must not accumulate
    // overlapping copies until the database gives up.
    foreach (['qeema:pipeline:sweep', 'qeema:index', 'qeema:index:publish'] as $signature) {
        expect(scheduled($signature)->withoutOverlapping)->toBeTrue("{$signature} must not overlap itself.");
    }
});

it('gives every overlap lock an expiry', function (): void {
    // Laravel's default is a full day, so a task killed mid-run would hold its
    // lock until tomorrow — a stopped pipeline that looks exactly like an idle
    // one.
    foreach (['qeema:pipeline:sweep', 'qeema:index', 'qeema:index:publish'] as $signature) {
        expect(scheduled($signature)->expiresAt)->toBeLessThan(1440, "{$signature} lock never expires.");
    }
});

it('does not let a slow drain delay the heartbeat behind it', function (): void {
    // The heartbeat is how anyone finds out the drain is slow, so it must not
    // be queued behind it.
    expect(scheduled('qeema:index')->runInBackground)->toBeTrue();
});

it('fetches exchange rates every hour', function (): void {
    // Hourly rather than daily: these currencies move within a day, and a
    // deployment that was down at the scheduled hour should catch up at the
    // next one rather than publishing without a conversion until tomorrow.
    expect(scheduled('qeema:fx:fetch')->expression)->toBe('0 * * * *');
});

it('retrains the nowcast model every six hours', function (): void {
    // The ML service holds the fitted model in memory, so a restart reverts
    // every imputed price to the fallback heuristic until this runs again.
    expect(scheduled('qeema:nowcast:train')->expression)->toBe('0 */6 * * *');
});

it('looks for coordinated manipulation daily', function (): void {
    // The detector existed from Phase 6 and had no caller, so the platform's
    // only defence against a coordinated cluster was a module nothing ran.
    expect(scheduled('qeema:reporters:bias')->expression)->toBe('20 3 * * *');
});

it('fetches configured open datasets daily', function (): void {
    // The runner existed with no caller at all, so a scraper source configured
    // in the admin panel was never fetched. A stock deployment configures none,
    // which is what makes a scheduled fetch safe.
    expect(scheduled('qeema:scrape')->expression)->toBe('40 2 * * *');
});

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Console\Commands\SchedulerHeartbeatCommand;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/*
|--------------------------------------------------------------------------
| Proof that the clock is running
|--------------------------------------------------------------------------
|
| A stopped scheduler is the worst failure this platform has, because it looks
| like nothing at all: the API answers, the dashboard renders, every container
| reports healthy, and the published figures quietly stop moving.
|
| The scheduler container's healthcheck runs `--check`, so a stopped clock
| becomes an unhealthy container within a few minutes rather than a question
| nobody asks for a month.
|
*/

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('records a heartbeat', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC'));

    $this->artisan('qeema:scheduler:heartbeat')->assertSuccessful();

    expect(Cache::get(SchedulerHeartbeatCommand::CACHE_KEY))
        ->toBe(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC')->toIso8601String());
});

it('passes its check when the clock is ticking', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC'));
    $this->artisan('qeema:scheduler:heartbeat')->assertSuccessful();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:01:30', 'UTC'));

    // One missed minute is ordinary: a slow command, a restart, a loaded host.
    $this->artisan('qeema:scheduler:heartbeat', ['--check' => true])->assertSuccessful();
});

it('fails its check once the clock has stopped', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:00:00', 'UTC'));
    $this->artisan('qeema:scheduler:heartbeat')->assertSuccessful();

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-10 12:05:00', 'UTC'));

    $this->artisan('qeema:scheduler:heartbeat', ['--check' => true])
        ->expectsOutputToContain('the clock has stopped')
        ->assertFailed();
});

it('fails its check when no heartbeat was ever recorded', function (): void {
    // A scheduler that never started at all, which is the same outcome for the
    // published index and must be the same outcome for the healthcheck.
    $this->artisan('qeema:scheduler:heartbeat', ['--check' => true])->assertFailed();
});

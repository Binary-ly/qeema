<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Console\Commands\PipelineHealthCommand;
use App\Console\Commands\PipelineSweepCommand;
use App\Console\Commands\PublishIndexCommand;
use App\Console\Commands\RecomputeIndexCommand;
use App\Console\Commands\SchedulerHeartbeatCommand;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| The clock
|--------------------------------------------------------------------------
|
| Everything below is what makes "live" a property of the deployment rather
| than a description of the demo. Without it the pipeline still resolves
| submissions — the jobs are dispatched on write — but corrections never reach
| published figures, no new calendar day is ever published, and anything the
| fast path drops stays dropped.
|
| Three rules apply throughout:
|
|   withoutOverlapping()  a minute-cadence task that occasionally takes longer
|                         than a minute must not stack on itself
|   onOneServer()         these are cluster-wide singletons; two schedulers
|                         draining the same backlog is duplicated work at best
|   runInBackground()     a slow drain must not delay the heartbeat behind it,
|                         because the heartbeat is how anyone learns the drain
|                         is slow
|
| The overlap locks carry explicit expiries. Laravel's default is a day, which
| means a task killed mid-run holds its lock until tomorrow — a stopped pipeline
| that looks exactly like an idle one.
|
*/

// Proof of life. Read back by the scheduler container's healthcheck, so a
// stopped clock surfaces as an unhealthy container rather than as an index that
// silently stopped moving.
Schedule::command(SchedulerHeartbeatCommand::class)
    ->everyMinute()
    ->onOneServer()
    ->name('qeema-scheduler-heartbeat');

// The reconciler: adopts anything the dispatch-on-write path missed.
Schedule::command(PipelineSweepCommand::class)
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

// Corrections reach the published figures. The grace window and batch size come
// from configuration, so the schedule does not have to repeat them.
Schedule::command(RecomputeIndexCommand::class)
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

// New calendar days appear. Hourly rather than daily so a deployment that was
// down at midnight catches up within the hour, and so a late arrival for a date
// that never had a snapshot still gets one.
Schedule::command(PublishIndexCommand::class)
    ->hourly()
    ->withoutOverlapping(55)
    ->onOneServer()
    ->runInBackground();

// Says out loud whether the platform is still publishing. Every failure here
// looks like silence from the outside, so something has to do the looking.
Schedule::command(PipelineHealthCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer()
    ->runInBackground();

// Queue metrics for the Horizon dashboard.
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer();

// A week of failed jobs is enough to diagnose anything; beyond that it is only
// unbounded growth in Redis.
Schedule::command('queue:prune-failed --hours=168')
    ->daily()
    ->onOneServer();

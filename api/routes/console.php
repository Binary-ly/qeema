<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Console\Commands\DetectReporterBiasCommand;
use App\Console\Commands\FetchFxRatesCommand;
use App\Console\Commands\LinkIndexCommand;
use App\Console\Commands\PipelineHealthCommand;
use App\Console\Commands\PipelineSweepCommand;
use App\Console\Commands\PublishIndexCommand;
use App\Console\Commands\RecomputeIndexCommand;
use App\Console\Commands\RunScrapersCommand;
use App\Console\Commands\SchedulerHeartbeatCommand;
use App\Console\Commands\TrainNowcastCommand;
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

// A basket revision takes effect on a date, and from that morning the new
// version needs an anchor or it publishes no level at all. Daily and idempotent:
// on almost every day it finds every basket already anchored and does nothing,
// and it will not overwrite an existing anchor without --force, so running it
// unattended cannot restate published history.
//
// Before the publisher, so a newly-effective basket is anchored in the same
// cycle that first publishes under it.
Schedule::command(LinkIndexCommand::class)
    ->dailyAt('00:20')
    ->withoutOverlapping(30)
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

// Exchange rates, for the countries that have an automatic source. Hourly
// rather than daily because these currencies move within a day, and because a
// deployment that was down at the scheduled hour should catch up at the next
// one rather than publishing without a conversion until tomorrow. A country on
// manual entry makes this a no-op.
Schedule::command(FetchFxRatesCommand::class)
    ->hourly()
    ->withoutOverlapping(55)
    ->onOneServer()
    ->runInBackground();

// Fits the nowcast models on observed history. Every six hours rather than
// daily because the ML service holds the fitted model in memory: a container
// restart loses it, and until the next run every imputed price falls back to a
// labelled heuristic. The health check reports that state rather than leaving
// it to be discovered.
Schedule::command(TrainNowcastCommand::class)
    ->everySixHours()
    ->withoutOverlapping(120)
    ->onOneServer()
    ->runInBackground();

// Fetches whatever open datasets an operator has configured. A stock
// deployment has none, so this does nothing until somebody sets one up —
// which is why it is safe on a schedule. Daily, because an open dataset that
// updates more often than that is unusual and politeness costs nothing.
Schedule::command(RunScrapersCommand::class)
    ->dailyAt('02:40')
    ->withoutOverlapping(180)
    ->onOneServer()
    ->runInBackground();

// Looks for reporters whose prices sit consistently away from their
// neighbours. Daily rather than hourly: the signal is a pattern across weeks of
// somebody's history, and running it more often would only produce the same
// answer at greater cost. It flags for a human and blocks nobody.
Schedule::command(DetectReporterBiasCommand::class)
    ->dailyAt('03:20')
    ->withoutOverlapping(120)
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

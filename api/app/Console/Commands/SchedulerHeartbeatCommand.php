<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Proof that the clock is running.
 *
 * A stopped scheduler is the worst failure this platform has, because it looks
 * like nothing at all: the API answers, the dashboard renders, every container
 * reports healthy, and the published figures quietly stop moving. Nobody
 * notices until somebody asks why the index has said the same thing for a week.
 *
 * So the schedule writes a heartbeat every minute, the scheduler container's
 * healthcheck reads it back, and a stopped clock becomes an unhealthy container
 * within three minutes instead of an unanswered question in March.
 */
final class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'qeema:scheduler:heartbeat
                            {--check : Exit non-zero if the last heartbeat is too old}';

    protected $description = 'Record that the scheduler ran, or verify that it recently did';

    public const CACHE_KEY = 'qeema:scheduler:last_tick';

    /**
     * Three missed minutes, not one.
     *
     * A single missed tick is ordinary — a slow command, a container restart,
     * a machine under load. Three consecutive misses is a stopped clock.
     */
    private const MAX_AGE_SECONDS = 180;

    public function handle(): int
    {
        return $this->option('check') ? $this->check() : $this->record();
    }

    private function record(): int
    {
        $now = CarbonImmutable::now();

        // Held well past the staleness threshold so `--check` can tell "the
        // scheduler stopped four minutes ago" from "the key expired", which are
        // the same absence but not the same problem.
        Cache::put(self::CACHE_KEY, $now->toIso8601String(), 3600);

        return self::SUCCESS;
    }

    private function check(): int
    {
        $last = Cache::get(self::CACHE_KEY);

        if (! is_string($last)) {
            $this->error('No scheduler heartbeat has ever been recorded.');

            return self::FAILURE;
        }

        $age = (int) CarbonImmutable::parse($last)->diffInSeconds(CarbonImmutable::now(), absolute: true);

        if ($age > self::MAX_AGE_SECONDS) {
            $this->error("Scheduler heartbeat is {$age}s old; the clock has stopped.");

            return self::FAILURE;
        }

        $this->info("Scheduler heartbeat is {$age}s old.");

        return self::SUCCESS;
    }
}

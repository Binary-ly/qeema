<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Pipeline\HealthCheck;
use App\Services\Pipeline\PipelineHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Says out loud whether the platform is still publishing.
 *
 * Runs on the schedule so that a degraded pipeline appears in the log the
 * operator already reads, rather than waiting for somebody to think to look at
 * a dashboard. `--strict` exits non-zero, which is what an external monitor
 * wants: this is the one command in the platform designed to be polled by
 * something that pages a human.
 */
final class PipelineHealthCommand extends Command
{
    protected $signature = 'qeema:pipeline:health
                            {--strict : Exit non-zero if anything is not ok}';

    protected $description = 'Report whether submissions, recomputation, publication and screening are keeping up';

    public function handle(PipelineHealth $health): int
    {
        $checks = $health->checks();
        $overall = $health->overallStatus($checks);

        foreach ($checks as $check) {
            $this->line(sprintf(
                '%-16s %-9s %s',
                $check->key,
                $check->status,
                $check->summary,
            ));

            if ($check->isOk()) {
                continue;
            }

            // Structured, so a log pipeline can alert on it without parsing
            // prose. The summary is included because whoever reads the alert at
            // three in the morning needs the sentence, not just the key.
            Log::warning('Pipeline health degraded', [
                'check' => $check->key,
                'status' => $check->status,
                'summary' => $check->summary,
                'age_seconds' => $check->ageSeconds,
                ...$check->detail,
            ]);
        }

        $this->newLine();
        $this->line("overall: {$overall}");

        // Ordinarily succeeds even when degraded: this runs every five minutes
        // on the scheduler, and a task that fails whenever the platform is
        // merely behind would bury the times it is genuinely stopped.
        if ($this->option('strict') && $overall !== HealthCheck::OK) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

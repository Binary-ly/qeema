<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Services\Pipeline\HealthCheck;
use App\Services\Pipeline\PipelineHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The numbers, for the people who can act on them.
 *
 * The public health endpoint reports states and ages; the counts live here,
 * behind the login. This is the first thing an operator sees on opening the
 * panel, which is the point: a platform whose failures all look like silence
 * needs its silence measured somewhere people already look.
 */
final class PipelineHealthWidget extends StatsOverviewWidget
{
    protected ?string $heading = 'Pipeline';

    protected ?string $pollingInterval = '60s';

    protected static ?int $sort = -10;

    /**
     * @return list<Stat>
     */
    protected function getStats(): array
    {
        $health = app(PipelineHealth::class);

        return array_map(
            fn (HealthCheck $check): Stat => $this->stat($check),
            $health->cachedChecks(),
        );
    }

    private function stat(HealthCheck $check): Stat
    {
        $value = $this->valueFor($check);

        return Stat::make($this->label($check->key), $value)
            ->description($check->summary)
            ->descriptionIcon($check->isOk() ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
            ->color(match ($check->status) {
                HealthCheck::STALLED => 'danger',
                HealthCheck::DEGRADED => 'warning',
                default => 'success',
            });
    }

    /**
     * The most useful single number for each check.
     *
     * Counts where a count is what an operator acts on, ages where lateness is
     * the problem, and the status word where neither adds anything.
     */
    private function valueFor(HealthCheck $check): string
    {
        $detail = $check->detail;

        return match (true) {
            array_key_exists('waiting', $detail) => (string) $detail['waiting'],
            array_key_exists('stale', $detail) && is_int($detail['stale']) => (string) $detail['stale'],
            array_key_exists('failures', $detail) => (string) $detail['failures'],
            $check->ageSeconds !== null => $this->humanAge($check->ageSeconds),
            default => ucfirst($check->status),
        };
    }

    private function humanAge(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => "{$seconds}s",
            $seconds < 7200 => intdiv($seconds, 60).'m',
            $seconds < 172800 => intdiv($seconds, 3600).'h',
            default => intdiv($seconds, 86400).'d',
        };
    }

    private function label(string $key): string
    {
        return match ($key) {
            'scheduler' => 'Scheduler',
            'resolution' => 'Awaiting resolution',
            'recomputation' => 'Stale snapshots',
            'publication' => 'Publication',
            'exchange_rates' => 'Exchange rates',
            'review_queue' => 'Awaiting review',
            'matching' => 'Matching service',
            'imputation' => 'Imputation source',
            'failed_jobs' => 'Failed jobs (24h)',
            default => ucfirst(str_replace('_', ' ', $key)),
        };
    }
}

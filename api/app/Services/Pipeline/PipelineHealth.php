<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Console\Commands\SchedulerHeartbeatCommand;
use App\Models\Country;
use App\Models\FxRate;
use App\Models\IndexSnapshot;
use App\Models\IndexSnapshotItem;
use App\Models\Submission;
use App\Services\Index\IndexStaleness;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Is the platform still publishing?
 *
 * Every failure this guards against looks like success from the outside. The
 * API answers, the dashboard renders, the containers report healthy — and the
 * published figures stop moving, or start being computed from prices nobody
 * screened, or quietly lose their dollar conversion. None of that raises an
 * error anywhere.
 *
 * So the checks are phrased as the invariants the pipeline promises, and each
 * one is a question with an operational answer: *if this is not ok, what does
 * an operator do about it?* A signal nobody can act on is noise that trains
 * people to ignore the ones they can.
 */
final class PipelineHealth
{
    /**
     * Recomputed at most once a minute.
     *
     * The public health endpoint backs a container healthcheck that runs every
     * ten seconds; without this the platform would spend a measurable share of
     * its database on answering whether it is well.
     */
    private const CACHE_KEY = 'qeema:pipeline:health';

    private const CACHE_SECONDS = 60;

    public function __construct(private readonly IndexStaleness $staleness = new IndexStaleness) {}

    /**
     * @return list<HealthCheck>
     */
    public function checks(): array
    {
        return [
            $this->scheduler(),
            $this->resolution(),
            $this->recomputation(),
            $this->publication(),
            $this->exchangeRates(),
            $this->reviewBacklog(),
            $this->matching(),
            $this->imputation(),
            $this->failedJobs(),
        ];
    }

    /**
     * The same checks, at most once a minute.
     *
     * Primitives go into the cache, never the objects themselves. Redis is
     * shared across every container and across a deploy, so a serialised domain
     * object outlives the code that defined it: the first request after this
     * shipped returned a 500 — `__PHP_Incomplete_Class` — because a value
     * written by one version was read by another. An array survives that; an
     * object cannot be made to.
     *
     * @return list<HealthCheck>
     */
    public function cachedChecks(): array
    {
        /** @var list<array<string, mixed>> $raw */
        $raw = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => array_map(
            static fn (HealthCheck $check): array => [
                'key' => $check->key,
                'status' => $check->status,
                'summary' => $check->summary,
                'age_seconds' => $check->ageSeconds,
                'detail' => $check->detail,
            ],
            $this->checks(),
        ));

        return array_map(
            static fn (array $check): HealthCheck => new HealthCheck(
                key: (string) ($check['key'] ?? 'unknown'),
                status: (string) ($check['status'] ?? HealthCheck::OK),
                summary: (string) ($check['summary'] ?? ''),
                ageSeconds: isset($check['age_seconds']) ? (int) $check['age_seconds'] : null,
                detail: is_array($check['detail'] ?? null) ? $check['detail'] : [],
            ),
            $raw,
        );
    }

    /**
     * The worst thing currently true.
     *
     * @param  list<HealthCheck>  $checks
     */
    public function overallStatus(array $checks): string
    {
        foreach ([HealthCheck::STALLED, HealthCheck::DEGRADED] as $status) {
            foreach ($checks as $check) {
                if ($check->status === $status) {
                    return $status;
                }
            }
        }

        return HealthCheck::OK;
    }

    /**
     * Is the clock running at all?
     *
     * First, because everything below it is downstream of the scheduler. A
     * stopped clock explains every other symptom at once, and an operator who
     * starts anywhere else is debugging a consequence.
     */
    private function scheduler(): HealthCheck
    {
        $last = Cache::get(SchedulerHeartbeatCommand::CACHE_KEY);

        if (! is_string($last)) {
            return HealthCheck::stalled(
                'scheduler',
                'The scheduler has never reported in. Nothing is being published.',
            );
        }

        $age = $this->ageInSeconds($last);

        return $age > 180
            ? HealthCheck::stalled('scheduler', 'The scheduler has stopped. The index is frozen.', $age)
            : HealthCheck::ok('scheduler', 'The clock is running.', $age);
    }

    /**
     * Are inbound submissions being turned into observations?
     */
    private function resolution(): HealthCheck
    {
        $oldest = Submission::query()
            ->awaitingPipeline()
            ->min('created_at');

        $waiting = (int) Submission::query()->awaitingPipeline()->count();

        if ($oldest === null) {
            return HealthCheck::ok('resolution', 'Nothing is waiting to be resolved.', 0, ['waiting' => 0]);
        }

        $age = $this->ageInSeconds((string) $oldest);
        $threshold = $this->alertSeconds();

        return $age > $threshold
            ? HealthCheck::degraded(
                'resolution',
                'Submissions are not being resolved. Both the dispatch and the sweeper are failing.',
                $age,
                ['waiting' => $waiting],
            )
            : HealthCheck::ok('resolution', 'Submissions are being resolved.', $age, ['waiting' => $waiting]);
    }

    /**
     * Are corrections reaching the published figures?
     */
    private function recomputation(): HealthCheck
    {
        $oldest = $this->staleness->oldestStaleAt();
        $pending = $this->staleness->pendingCount();

        if ($oldest === null) {
            return HealthCheck::ok('recomputation', 'No snapshot is waiting to be recomputed.', 0, ['stale' => $pending]);
        }

        $age = (int) $oldest->diffInSeconds(CarbonImmutable::now(), absolute: true);

        return $age > $this->alertSeconds()
            ? HealthCheck::degraded(
                'recomputation',
                'Published figures are out of date with the observations beneath them.',
                $age,
                ['stale' => $pending],
            )
            : HealthCheck::ok('recomputation', 'Corrections are reaching published figures.', $age, ['stale' => $pending]);
    }

    /**
     * Is a new calendar day being published, in each country's own time?
     */
    private function publication(): HealthCheck
    {
        $behind = [];

        foreach (Country::query()->where('is_active', true)->get() as $country) {
            $latest = IndexSnapshot::query()
                ->where('country_id', $country->id)
                ->max('snapshot_date');

            $today = CarbonImmutable::now($country->timezone)->toDateString();

            if ($latest === null) {
                $behind[$country->code] = 'never';

                continue;
            }

            if (CarbonImmutable::parse((string) $latest)->toDateString() < $today) {
                $behind[$country->code] = CarbonImmutable::parse((string) $latest)->toDateString();
            }
        }

        return $behind === []
            ? HealthCheck::ok('publication', 'Every country has a figure for today.')
            : HealthCheck::degraded(
                'publication',
                'A country has no published figure for its own today.',
                detail: ['behind' => $behind],
            );
    }

    /**
     * Will the dollar conversion still be there tomorrow?
     *
     * Degrading here is genuinely urgent in a currency that moves: past the
     * staleness horizon the platform stops converting altogether and publishes
     * `cost_usd` as null, which is honest and is also the figure most external
     * consumers are reading.
     */
    private function exchangeRates(): HealthCheck
    {
        $stale = [];

        foreach (Country::query()->where('is_active', true)->get() as $country) {
            $latest = FxRate::query()->where('country_id', $country->id)->max('rate_date');

            /** @var array<string, mixed> $fx */
            $fx = $country->fx_config ?? [];
            $horizon = (int) ($fx['max_staleness_days'] ?? 7);

            if ($latest === null) {
                $stale[$country->code] = 'never';

                continue;
            }

            $days = (int) CarbonImmutable::parse((string) $latest)
                ->diffInDays(CarbonImmutable::now($country->timezone), absolute: true);

            if ($days > $horizon) {
                $stale[$country->code] = "{$days} days";
            }
        }

        return $stale === []
            ? HealthCheck::ok('exchange_rates', 'Exchange rates are within their staleness horizon.')
            : HealthCheck::degraded(
                'exchange_rates',
                'An exchange rate is past its horizon; dollar figures are being withheld.',
                detail: ['stale' => $stale],
            );
    }

    /**
     * Is anybody working the review queue?
     *
     * Size is not the signal — a large queue being worked through is healthy.
     * The signal is *age*: a submission nobody has looked at for a week means
     * the queue has an owner in theory only, and every price in it is one the
     * platform has decided not to publish.
     */
    private function reviewBacklog(): HealthCheck
    {
        $waiting = (int) Submission::query()->awaitingReview()->count();
        $oldest = Submission::query()->awaitingReview()->min('observed_at');

        if ($oldest === null) {
            return HealthCheck::ok('review_queue', 'Nothing is awaiting review.', 0, ['waiting' => 0]);
        }

        $age = $this->ageInSeconds((string) $oldest);
        $limit = (int) config('qeema.pipeline.review_alert_days') * 86400;

        return $age > $limit
            ? HealthCheck::degraded(
                'review_queue',
                'Submissions have been waiting for a human longer than they should.',
                $age,
                ['waiting' => $waiting],
            )
            : HealthCheck::ok('review_queue', 'The review queue is being worked.', $age, ['waiting' => $waiting]);
    }

    /**
     * Is the matcher reachable?
     */
    private function matching(): HealthCheck
    {
        return Cache::get('qeema:ml:circuit') === true
            ? HealthCheck::degraded(
                'matching',
                'The matching service is unreachable; submissions are queueing for human review.',
            )
            : HealthCheck::ok('matching', 'The matching service is answering.');
    }

    /**
     * Are estimates coming from the model, or from the fallback?
     *
     * The nowcast model lives in the ML service's memory, so a container
     * restart unfits it and every imputed price silently reverts to a ±30%
     * heuristic until the next training run. The figures still publish, still
     * carry an interval and are still labelled imputed — they are simply much
     * cruder than the model card describes, and nothing else would say so.
     */
    private function imputation(): HealthCheck
    {
        $recent = IndexSnapshotItem::query()
            ->where('is_imputed', true)
            ->whereNotNull('imputation_method')
            ->orderByDesc('id')
            ->limit(200)
            ->pluck('imputation_method');

        if ($recent->isEmpty()) {
            return HealthCheck::ok('imputation', 'Nothing has needed imputing.');
        }

        $modelled = $recent->filter(fn (string $method): bool => $method === 'lightgbm_quantile')->count();

        return $modelled === 0
            ? HealthCheck::degraded(
                'imputation',
                'Estimates are coming from the fallback heuristic, not the trained model.',
                detail: ['modelled_share' => 0.0, 'sampled' => $recent->count()],
            )
            : HealthCheck::ok(
                'imputation',
                'Estimates are coming from the trained model.',
                detail: ['modelled_share' => round($modelled / $recent->count(), 3), 'sampled' => $recent->count()],
            );
    }

    /**
     * Has anything given up entirely?
     *
     * Distinct from every check above, which measure lateness. A failed job is
     * a code path that broke rather than a queue that is behind, and the two
     * want different responses.
     */
    private function failedJobs(): HealthCheck
    {
        $since = CarbonImmutable::now()->subDay();

        $failures = (int) DB::table('failed_jobs')->where('failed_at', '>=', $since)->count();

        return $failures === 0
            ? HealthCheck::ok('failed_jobs', 'No job has failed in the last day.', detail: ['failures' => 0])
            : HealthCheck::degraded(
                'failed_jobs',
                'Jobs are failing outright. Something is broken rather than behind.',
                detail: ['failures' => $failures],
            );
    }

    private function alertSeconds(): int
    {
        return (int) config('qeema.pipeline.alert_minutes') * 60;
    }

    private function ageInSeconds(string $timestamp): int
    {
        return (int) CarbonImmutable::parse($timestamp)->diffInSeconds(CarbonImmutable::now(), absolute: true);
    }
}

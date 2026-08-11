<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Fx;

use App\Models\Country;
use App\Models\FxRate;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Decides which exchange rate an index snapshot is converted at.
 *
 * The staleness rule here is the part that matters. In a currency that moves
 * several percent a month, silently converting today's basket at a rate from
 * three weeks ago produces a USD figure that is confidently wrong — and looks
 * exactly like a correct one. So a stale rate is used, because publishing
 * nothing is usually worse, but it is always **flagged**, and beyond a
 * configurable horizon it is refused outright and `cost_usd` is published as
 * null.
 *
 * A null USD cost is an honest statement that the conversion is unknown. An
 * invented one is not.
 */
final class FxRateResolver
{
    public const TYPE_PARALLEL = 'parallel';

    public const TYPE_OFFICIAL = 'official';

    /**
     * Resolve the rate to use for a country on a date.
     *
     * Returns null when nothing usable exists, which the caller turns into a
     * snapshot with `cost_usd = null` rather than an unconverted figure
     * presented as dollars.
     */
    public function resolve(Country $country, DateTimeInterface $date): ?ResolvedRate
    {
        $requestedType = $country->fxRateType();
        $maxStaleness = $this->maxStalenessDays($country);
        $on = CarbonImmutable::instance($date)->startOfDay();

        $exact = $this->rateOn($country, $on);

        if ($exact !== null) {
            $value = $exact->rateFor($requestedType);

            if ($value !== null && $value > 0.0) {
                return new ResolvedRate($value, $requestedType, $on, isStale: false, ageDays: 0);
            }
        }

        // Fall back to the most recent rate at or before this date. Never a
        // later one: converting a historical figure at a rate that had not
        // happened yet would be a subtle form of hindsight.
        $previous = FxRate::query()
            ->where('country_id', $country->id)
            ->where('rate_date', '<=', $on->toDateString())
            ->orderByDesc('rate_date')
            // Same precedence as above, so falling back to an older day does
            // not quietly switch which source is trusted.
            ->orderByDesc('is_manual')
            ->orderByDesc('fetched_at')
            ->first();

        if ($previous === null) {
            return null;
        }

        $value = $previous->rateFor($requestedType);

        if ($value === null || $value <= 0.0) {
            return null;
        }

        $ageDays = (int) $previous->rate_date->diffInDays($on);

        if ($ageDays > $maxStaleness) {
            // Beyond the horizon the operator declared acceptable. Refusing is
            // the honest answer: nobody can say what today's basket costs in
            // dollars using a rate this old.
            return null;
        }

        return new ResolvedRate(
            rate: $value,
            type: $requestedType,
            rateDate: CarbonImmutable::instance($previous->rate_date),
            isStale: true,
            ageDays: $ageDays,
        );
    }

    /**
     * The rate for a day, when several sources have one.
     *
     * `fx_rates` is keyed `(country_id, rate_date, source)`, so an automatic
     * fetch and an operator's correction can both exist for the same day. The
     * human wins. Ordering by recency alone — which is what this did before any
     * provider existed — would mean tonight's scheduled fetch silently
     * overruling the correction somebody typed this afternoon after speaking to
     * a trader, and nobody would see it happen.
     */
    private function rateOn(Country $country, CarbonImmutable $date): ?FxRate
    {
        return FxRate::query()
            ->where('country_id', $country->id)
            ->whereDate('rate_date', $date->toDateString())
            ->orderByDesc('is_manual')
            ->orderByDesc('fetched_at')
            ->first();
    }

    private function maxStalenessDays(Country $country): int
    {
        /** @var array<string, mixed> $fx */
        $fx = $country->fx_config ?? [];

        return (int) ($fx['max_staleness_days'] ?? 7);
    }
}

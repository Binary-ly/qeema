<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Fx\Providers;

use App\Models\Country;
use App\Services\Fx\FxQuote;
use App\Services\Fx\FxRateProvider;
use DateTimeInterface;

/**
 * No automatic source. An operator types the rate in.
 *
 * The shipped default, and not an admission of defeat. In most of the economies
 * this platform is for, the rate that matters is the parallel one, and there is
 * no free machine-readable feed for it that anyone would stake a published
 * figure on — the sources that exist are behind an API key, which the platform
 * refuses to depend on (constraint C1), or are a website whose terms have not
 * been reviewed.
 *
 * So this exists to make "there is no automatic source here" an explicit,
 * configured, logged state rather than a missing binding that looks like a bug.
 * The operator enters rates in the admin panel; the health check warns before
 * the last one goes stale enough to withdraw dollar figures.
 */
final class ManualFxProvider implements FxRateProvider
{
    public const KEY = 'manual';

    public function key(): string
    {
        return self::KEY;
    }

    public function fetch(Country $country, DateTimeInterface $date): ?FxQuote
    {
        return null;
    }
}

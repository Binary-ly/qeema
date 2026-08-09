<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Fx;

use App\Models\Country;
use DateTimeInterface;

/**
 * Source of exchange rates for a country.
 *
 * Pluggable because the honest answer to "where does the parallel rate come
 * from" differs everywhere, and often has no API at all. A deployment that
 * cannot fetch a rate automatically must still be able to publish one an
 * operator typed in, rather than publishing nothing.
 */
interface FxRateProvider
{
    /** Stable key, matched against `countries.fx_config.provider`. */
    public function key(): string;

    /**
     * Fetch the rate for a date, or null if this provider has none.
     *
     * Null rather than an exception or a guess: a missing rate is an ordinary
     * condition, and the caller degrades by falling back to the most recent
     * usable rate and flagging it as stale.
     */
    public function fetch(Country $country, DateTimeInterface $date): ?FxQuote;
}

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Fx;

use App\Models\Country;
use App\Services\Fx\Providers\ManualFxProvider;
use Illuminate\Support\Facades\Log;

/**
 * Which source a country's rates come from.
 *
 * A country names its provider by key in its own configuration file, so adding
 * a source means writing a class and registering it rather than editing
 * anything that already works — the same shape as the scraper registry.
 *
 * An unknown key falls back to manual entry rather than throwing. A typo in a
 * country file should degrade to "an operator types the rate in", which is a
 * working system, not to a scheduled task that fails every hour and takes the
 * other countries' rates down with it.
 */
final class FxProviderRegistry
{
    /** @var array<string, FxRateProvider> */
    private array $providers = [];

    public function register(FxRateProvider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): FxRateProvider
    {
        return $this->providers[$key] ?? $this->providers[ManualFxProvider::KEY];
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    /**
     * The provider a country has asked for.
     */
    public function for(Country $country): FxRateProvider
    {
        /** @var array<string, mixed> $fx */
        $fx = $country->fx_config ?? [];
        $key = $fx['provider'] ?? ManualFxProvider::KEY;

        if (! is_string($key) || ! $this->has($key)) {
            Log::warning('Unknown exchange rate provider; falling back to manual entry', [
                'country' => $country->code,
                'requested' => is_string($key) ? $key : gettype($key),
                'registered' => $this->keys(),
            ]);

            return $this->get(ManualFxProvider::KEY);
        }

        return $this->get($key);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->providers);
    }
}

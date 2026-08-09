<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * The public API is unauthenticated by design (constraint C6), so per-IP
     * rate limiting is the only thing standing between it and a scraper. The
     * limit is configuration, because a self-hosting operator with different
     * traffic should not have to patch code to change it.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('public-api', function (Request $request): Limit {
            return Limit::perMinute((int) config('qeema.api.rate_limit_per_minute'))
                ->by($request->ip() ?? 'unknown');
        });
    }
}

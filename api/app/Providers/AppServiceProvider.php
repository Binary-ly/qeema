<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureUrlGeneration();
        $this->configureRateLimiting();
    }

    /**
     * Make APP_URL authoritative for generated URLs.
     *
     * Laravel builds redirects from the incoming request by default, which
     * silently loses the port behind nginx and produces a Location header
     * pointing at port 80. In the demo stack that turns "open the admin panel"
     * into a connection refused. A self-hosted deployment sitting behind a
     * reverse proxy has the same problem with scheme, so APP_URL — which the
     * operator has already had to set correctly — wins.
     */
    private function configureUrlGeneration(): void
    {
        $url = (string) config('app.url');

        if ($url === '') {
            return;
        }

        URL::forceRootUrl($url);

        if (str_starts_with($url, 'https://')) {
            URL::forceScheme('https');
        }
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

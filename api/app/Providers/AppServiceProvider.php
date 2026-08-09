<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Providers;

use App\Support\Scraping\OpenDataCsvScraper;
use App\Support\Scraping\ScraperRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scrapers are registered once as a singleton so a source can name one
        // by key in configuration rather than the pipeline knowing about them.
        $this->app->singleton(ScraperRegistry::class, function (): ScraperRegistry {
            $registry = new ScraperRegistry;
            $registry->register(new OpenDataCsvScraper);

            return $registry;
        });
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

        // Writes are limited per device rather than per IP: reporters in the
        // same town routinely share one mobile carrier NAT, so an IP-based
        // limit would throttle a whole community because one phone was busy.
        // Falling back to IP keeps a client that omits the header bounded.
        RateLimiter::for('submissions', function (Request $request): Limit {
            $reporterRef = (string) $request->input('reporter_ref', '');

            return Limit::perMinute((int) config('qeema.api.submission_rate_limit_per_minute'))
                ->by($reporterRef !== '' ? 'reporter:'.$reporterRef : 'ip:'.($request->ip() ?? 'unknown'));
        });
    }
}

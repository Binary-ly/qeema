<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Providers;

use App\Services\Fx\FxProviderRegistry;
use App\Services\Fx\Providers\GenericHttpFxProvider;
use App\Services\Fx\Providers\ManualFxProvider;
use App\Services\Index\IndexCalculator;
use App\Services\Index\ItemImputer;
use App\Services\Ml\MlClient;
use App\Services\Ml\MlClientInterface;
use App\Support\Scraping\OpenDataCsvScraper;
use App\Support\Scraping\ScraperRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scrapers are registered once as a singleton so a source can name one
        // by key in configuration rather than the pipeline knowing about them.
        // Callers depend on the interface; the HTTP client is the production
        // binding and a fake stands in as a peer during tests.
        $this->app->bind(MlClientInterface::class, MlClient::class);

        // The calculator gets an imputer by default, so a scheduled recompute
        // fills gaps rather than publishing partial baskets. It still degrades
        // honestly when the ML service is unreachable.
        $this->app->bind(IndexCalculator::class, fn ($app): IndexCalculator => new IndexCalculator(
            imputer: new ItemImputer($app->make(MlClientInterface::class)),
        ));

        // Exchange rate sources. The platform ships knowing how to read *a*
        // JSON endpoint and nothing about which one: every source worth having
        // for these currencies is behind an API key, and depending on one would
        // breach C1 and make the "no third-party keys, by construction" claim
        // in SECURITY.md untrue. An operator with a source describes it in
        // their own country file.
        $this->app->singleton(FxProviderRegistry::class, function (): FxProviderRegistry {
            $registry = new FxProviderRegistry;
            $registry->register(new ManualFxProvider);
            $registry->register(new GenericHttpFxProvider);

            return $registry;
        });

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
        $this->configureBladeDirectives();
    }

    /**
     * `@localised('reporter')` — an internal link that keeps the reader's
     * language. See LocalisedRoute for why a bare route() does not.
     */
    private function configureBladeDirectives(): void
    {
        Blade::directive(
            'localised',
            static fn (string $expression): string => "<?php echo e(\App\Support\Http\LocalisedRoute::to({$expression})); ?>",
        );
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

        // Bulk export is streamed but still expensive; limited far more
        // tightly than an ordinary read so one scraper cannot monopolise it.
        RateLimiter::for('export', fn (Request $request): Limit => Limit::perMinute((int) config('qeema.api.export_rate_limit_per_minute'))
            ->by($request->ip() ?? 'unknown'));

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

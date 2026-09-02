<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Country;
use App\Services\Dashboard\DashboardData;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Serves the reporter progressive web app.
 *
 * The page itself is a thin shell: everything interactive happens client-side
 * so the app keeps working with no connection. The server's job here is to pick
 * a locale, set the text direction and hand over the endpoints.
 */
final class ReporterController extends Controller
{
    public function __construct(private readonly DashboardData $data) {}

    public function __invoke(Request $request): View
    {
        $country = $this->resolveCountry($request);

        /** @var list<string> $available */
        $available = $country === null ? ['en'] : ($country->locales ?? ['en']);
        $default = $country === null ? 'en' : $country->default_locale;

        $locale = $this->resolveLocale($request, $available, $default);
        app()->setLocale($locale);

        return view('reporter', [
            'locale' => $locale,
            // Direction is derived from the locale rather than hardcoded, so a
            // country configured with a different RTL language works without a
            // code change (constraint C3).
            'direction' => $this->directionFor($locale),
            'availableLocales' => $available,
            'config' => [
                'countryCode' => $country?->code,
                'country' => null,
                'bootstrapUrl' => $country ? route('api.v1.bootstrap', ['countryCode' => $country->code]) : null,
                'submitUrl' => route('api.v1.submissions.store'),
                'csrfToken' => csrf_token(),
                'appVersion' => config('qeema.version'),
                // Which basket items nobody can price yet, so the picker can
                // ask for those first.
                //
                // This is the whole argument for the crowdsourced layer and the
                // app never made it: the dashboard says ten of fifteen items
                // have no price in any location, and the reporter was shown a
                // flat alphabetical list that treated the one nobody has ever
                // priced exactly like the one sixteen towns report weekly.
                //
                // Passed in the page's own config block rather than added to
                // the public API, because it is a presentation hint. It ships
                // in the cached shell, so a reporter offline for a week sees a
                // week-old hint — which is the right failure for a nudge.
                ...$this->needs($country, $this->directionFor($locale) === 'rtl'),
                'messages' => [
                    'queued' => __('reporter.queued'),
                    'synced' => __('reporter.synced'),
                    'failed' => __('reporter.failed'),
                ],
            ],
        ]);
    }

    public function offline(): View
    {
        return view('reporter-offline');
    }

    /**
     * The basket items no location can price, and how many there are.
     *
     * An item with a price in zero locations is not a rendering gap; it is a
     * category of thing a child needs that nothing in this deployment tracks,
     * and it is exactly what one person with a phone can fix in thirty seconds.
     *
     * @return array{needs: list<string>, needsCount: int, basketCount: int}
     */
    private function needs(?Country $country, bool $preferLocalNames): array
    {
        if ($country === null) {
            return ['needs' => [], 'needsCount' => 0, 'basketCount' => 0, 'meter' => []];
        }

        $basket = $this->data->basketCoverage(
            $country,
            $this->data->currentSnapshots($country),
            preferLocalNames: $preferLocalNames,
        );

        /** @var list<string> $needs */
        $needs = [];
        /** @var list<array<string, mixed>> $meter */
        $meter = [];

        $peak = 0.0;

        foreach ($basket as $row) {
            $peak = max($peak, (float) $row['weight']);
        }

        $peak = max($peak, 0.0001);

        foreach ($basket as $row) {
            $unpriced = $row['locations'] === 0;

            if ($unpriced) {
                $needs[] = (string) $row['code'];
            }

            $meter[] = [
                'code' => (string) $row['code'],
                'label' => (string) $row['label'],
                // Same scale as the dashboard's device: floored at 40% of full
                // height, because at true scale the lightest item is a two-pixel
                // smudge and the row stops reading as a row.
                'height' => round(40 + ((float) $row['weight'] / $peak) * 60, 1),
                'unpriced' => $unpriced,
            ];
        }

        return [
            'needs' => $needs,
            'needsCount' => count($needs),
            'basketCount' => count($basket),
            // The mark in the masthead, carrying this deployment's actual
            // basket. See the note beside it in the template for why it is here
            // and not only on the dashboard.
            'meter' => $meter,
        ];
    }

    private function resolveCountry(Request $request): ?Country
    {
        $code = $request->query('country');

        if (is_string($code) && $code !== '') {
            return Country::query()->where('code', strtoupper($code))->where('is_active', true)->first();
        }

        // Single-country deployments are the common case, so no country picker
        // is imposed on the reporter.
        return Country::query()->where('is_active', true)->orderBy('id')->first();
    }

    /**
     * @param  list<string>  $available
     */
    private function resolveLocale(Request $request, array $available, string $default): string
    {
        $requested = $request->query('locale');

        if (is_string($requested) && in_array($requested, $available, true)) {
            return $requested;
        }

        // Honour the device language when the country offers it, so a reporter
        // is not made to pick their own language on first run.
        $preferred = $request->getPreferredLanguage($available);

        return is_string($preferred) && $preferred !== '' ? $preferred : $default;
    }

    /**
     * Text direction for a locale.
     *
     * Kept as a list of RTL language subtags rather than a per-country flag:
     * direction is a property of the language, and a country offering both
     * Arabic and English needs it to change with the locale toggle.
     */
    private function directionFor(string $locale): string
    {
        $rtl = ['ar', 'fa', 'he', 'ur', 'ps', 'ckb', 'dv', 'yi'];
        $language = strtolower(explode('-', str_replace('_', '-', $locale))[0]);

        return in_array($language, $rtl, true) ? 'rtl' : 'ltr';
    }
}

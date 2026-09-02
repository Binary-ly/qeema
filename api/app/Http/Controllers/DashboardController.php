<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Country;
use App\Services\Dashboard\DashboardData;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public dashboard.
 *
 * Server-rendered. The page is readable, and every number on it is correct,
 * before a single byte of JavaScript executes — charts hydrate afterwards and
 * enhance what is already there. Two reasons, and neither is stylistic:
 *
 * 1. The Lighthouse performance target is measured on a throttled mid-tier
 *    phone, which is also the device most of this platform's readers actually
 *    hold. Shipping a framework to render text the server already has would
 *    spend that budget for nothing.
 * 2. A dashboard that renders nothing without JavaScript is a dashboard that
 *    shows nothing on a slow connection — the exact conditions in the places
 *    being measured.
 *
 * The map is inline SVG rather than a WebGL canvas, for the same reasons plus
 * one more: SVG elements are in the accessibility tree. See D-10 in PLAN.md.
 */
final class DashboardController extends Controller
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

        if ($country === null) {
            // A deployment with no active country is a real state on first boot,
            // before the seed has run. Say so plainly rather than 500.
            return view('dashboard.empty', [
                'locale' => $locale,
                'direction' => $this->directionFor($locale),
                'availableLocales' => $available,
            ]);
        }

        $snapshots = $this->data->currentSnapshots($country);

        // Whether the page's own language is the country's. Everything that
        // carries two names — locations, basket items — leads with this one.
        $local = $this->directionFor($locale) === 'rtl';

        $map = $this->data->mapPoints($country, $snapshots, preferLocalNames: $local);

        return view('dashboard.show', [
            'locale' => $locale,
            'direction' => $this->directionFor($locale),
            'availableLocales' => $available,
            'country' => $country,
            'countries' => Country::query()->where('is_active', true)->orderBy('name')->get(),
            'headline' => $this->data->headline($country, $snapshots),
            'basket' => $this->data->basketCoverage($country, $snapshots, preferLocalNames: $local),
            'snapshots' => $snapshots,
            'projection' => $map['projection'],
            'points' => $map['points'],
            'outline' => $map['outline'],
            'charts' => [
                'national' => $this->data->nationalSeries($country),
                'locations' => $this->data->locationSeries($country),
                'fx' => $this->data->fxSeries($country),
                'currency' => $country->currency_code,
            ],
            'apiUrl' => route('api.v1.index.current', ['countryCode' => $country->code]),
            'exportUrl' => route('api.v1.export.csv', ['countryCode' => $country->code]),
        ]);
    }

    private function resolveCountry(Request $request): ?Country
    {
        $code = $request->query('country');

        if (is_string($code) && $code !== '') {
            return Country::query()->where('code', strtoupper($code))->where('is_active', true)->first();
        }

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

        return in_array($default, $available, true) ? $default : ($available[0] ?? 'en');
    }

    /**
     * Direction follows the locale, so a country configured with a different
     * RTL language works without a code change (constraint C3).
     */
    private function directionFor(string $locale): string
    {
        $rtl = ['ar', 'fa', 'he', 'ur', 'ps', 'ku', 'dv'];

        return in_array(strtolower(substr($locale, 0, 2)), $rtl, true) ? 'rtl' : 'ltr';
    }
}

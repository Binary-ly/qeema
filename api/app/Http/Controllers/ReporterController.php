<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Country;
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

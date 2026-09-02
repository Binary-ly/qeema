<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Http;

/**
 * Internal links that keep the reader's language and country.
 *
 * Locale and country are carried in the query string, not in the path, because
 * the public API and the dashboard have to be linkable and cacheable without a
 * locale segment. The cost of that choice is that a bare `route('reporter')`
 * silently drops both: the next page falls back to the country's default
 * locale, so an English reader who clicked the logo — or "Report a price", or
 * the API link — was answered in Arabic.
 *
 * Every internal link between pages goes through here so the fallback cannot be
 * reintroduced one link at a time. Links to the API and to the CSV export do
 * not: those are documents, not pages, and they take their own parameters.
 */
final class LocalisedRoute
{
    /**
     * A named route with the current locale, and a country when one is known.
     *
     * The country is passed explicitly by the dashboard, which is the only page
     * that can change it. Everywhere else it is read back off the request, so a
     * reader who arrived on a non-default country keeps it when moving between
     * the reporter, the docs and the dashboard.
     */
    public static function to(string $name, ?string $country = null): string
    {
        $country ??= self::countryFromRequest();

        /** @var array<string, string> $query */
        $query = array_filter([
            'country' => $country,
            'locale' => app()->getLocale(),
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return route($name, $query);
    }

    private static function countryFromRequest(): ?string
    {
        $code = request()->query('country');

        return is_string($code) && $code !== '' ? $code : null;
    }
}

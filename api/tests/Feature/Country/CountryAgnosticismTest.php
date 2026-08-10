<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;

/**
 * Constraint C3, tested rather than asserted.
 *
 * The claim is that adding a country means adding a `countries/*.yaml` file and
 * nothing else. A grep for "Libya" in the source proves less than it appears
 * to — the interesting failures are structural: a basket that assumes wheat, a
 * direction derived from a hardcoded list of one, a projection that only works
 * at positive longitudes, a currency assumed to have two decimal places.
 *
 * So these tests load the shipped configurations and check the properties that
 * would break if something country-specific had leaked into code.
 */
function loadShippedConfigs(): array
{
    $directory = (string) config('qeema.countries_path');

    return (new CountryConfigLoader)->loadDirectory($directory);
}

it('ships more than one country', function (): void {
    // A single-country project can pass every other test here by accident.
    expect(count(loadShippedConfigs()))->toBeGreaterThan(1);
});

it('loads and imports every shipped configuration', function (): void {
    $importer = new CountryConfigImporter;

    foreach (loadShippedConfigs() as $config) {
        $summary = $importer->import($config);

        expect($summary->locations)->toBeGreaterThan(0)
            ->and($summary->canonicalItems)->toBeGreaterThan(0)
            ->and($summary->basketItems)->toBeGreaterThan(0);
    }
});

it('gives every country a basket whose weights sum to one', function (): void {
    foreach (loadShippedConfigs() as $config) {
        $sum = array_sum(array_column($config['basket']['items'], 'weight'));

        // Not cosmetic: the weights are the denominator of the coverage figure,
        // so a basket summing to 0.98 silently overstates coverage by 2%.
        expect(round($sum, 6))->toBe(1.0, "Basket weights for {$config['country']['code']} sum to {$sum}");
    }
});

it('covers the same categories in every country', function (): void {
    $expected = ['infant_nutrition', 'staples', 'protein', 'produce', 'medicine', 'school', 'hygiene', 'fuel', 'water'];

    foreach (loadShippedConfigs() as $config) {
        $categories = array_unique(array_column($config['basket']['items'], 'category'));
        $missing = array_diff($expected, $categories);

        expect($missing)->toBe([], "{$config['country']['code']} is missing: ".implode(', ', $missing));
    }
});

it('does not assume a single script, direction or currency subdivision', function (): void {
    $configs = loadShippedConfigs();

    $locales = [];
    $minorUnits = [];

    foreach ($configs as $config) {
        $locales[] = $config['country']['default_locale'];
        $minorUnits[] = $config['country']['currency']['minor_units'];
    }

    // The shipped set must actually exercise the differences, or the code could
    // hardcode any of them and still pass. Libya is Arabic/RTL with a
    // three-decimal dinar; Venezuela is Spanish/LTR with a two-decimal bolívar.
    expect(array_unique($locales))->toHaveCount(count($configs))
        ->and(count(array_unique($minorUnits)))->toBeGreaterThan(1);
});

it('does not assume a hemisphere', function (): void {
    $longitudes = [];

    foreach (loadShippedConfigs() as $config) {
        foreach ($config['locations'] as $location) {
            $longitudes[] = (float) $location['longitude'];
        }
    }

    // The map projection has to survive negative longitudes. A projection
    // written against one country's bounding box can pass every unit test and
    // still draw the second country off-canvas.
    expect(min($longitudes))->toBeLessThan(0.0)
        ->and(max($longitudes))->toBeGreaterThan(0.0);
});

it('lets each country choose different estimator settings', function (): void {
    $halfLives = [];

    foreach (loadShippedConfigs() as $config) {
        $halfLives[] = $config['index']['recency_half_life_days'];
    }

    // If every country used identical settings, `index:` might as well be a
    // constant — and the next contributor would reasonably make it one.
    expect(count(array_unique($halfLives)))->toBeGreaterThan(1);
});

it('keeps every shipped locale fully translated', function (): void {
    $locales = [];

    foreach (loadShippedConfigs() as $config) {
        foreach ($config['country']['locales'] as $locale) {
            $locales[$locale] = true;
        }
    }

    foreach (['dashboard', 'reporter'] as $file) {
        /** @var array<string, mixed> $reference */
        $reference = require lang_path("en/{$file}.php");

        foreach (array_keys($locales) as $locale) {
            $path = lang_path("{$locale}/{$file}.php");

            // A configured locale with no translation file renders an English
            // interface under a `lang` attribute claiming otherwise — which is
            // worse than not offering the locale at all.
            expect($path)->toBeReadableFile("Missing {$locale}/{$file}.php for a configured locale");

            /** @var array<string, mixed> $translated */
            $translated = require $path;

            expect(array_keys($translated))
                ->toEqualCanonicalizing(array_keys($reference), "{$locale}/{$file}.php has drifted from English");
        }
    }
});

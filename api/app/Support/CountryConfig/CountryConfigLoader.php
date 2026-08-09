<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\CountryConfig;

use App\Models\Basket;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads and validates a country configuration file.
 *
 * Country configuration is the mechanism that keeps the platform
 * country-agnostic (constraint C3), which makes it the place a self-hoster is
 * most likely to make a mistake. Validation is therefore thorough and the error
 * messages name the specific key at fault: someone adding their country should
 * not have to read this class to understand what went wrong.
 */
final class CountryConfigLoader
{
    /** Required top-level sections. */
    private const REQUIRED_SECTIONS = ['country', 'units', 'locations', 'canonical_items', 'basket'];

    /**
     * Load and validate every country file in a directory.
     *
     * @param  list<string>|null  $onlyCodes  restrict to these ISO codes
     * @return list<array<string, mixed>>
     */
    public function loadDirectory(string $directory, ?array $onlyCodes = null): array
    {
        $files = glob(rtrim($directory, '/').'/*.{yaml,yml}', GLOB_BRACE) ?: [];
        sort($files);

        $configs = [];

        foreach ($files as $file) {
            $config = $this->load($file);
            $code = strtoupper((string) $config['country']['code']);

            if ($onlyCodes !== null && ! in_array($code, array_map('strtoupper', $onlyCodes), true)) {
                continue;
            }

            $configs[] = $config;
        }

        return $configs;
    }

    /**
     * Load and validate a single country file.
     *
     * @return array<string, mixed>
     *
     * @throws CountryConfigException
     */
    public function load(string $file): array
    {
        if (! is_file($file)) {
            throw new CountryConfigException($file, ['File does not exist.']);
        }

        try {
            /** @var array<string, mixed>|null $parsed */
            $parsed = Yaml::parseFile($file);
        } catch (ParseException $e) {
            throw new CountryConfigException($file, ['YAML is malformed: '.$e->getMessage()]);
        }

        if (! is_array($parsed)) {
            throw new CountryConfigException($file, ['File is empty or is not a YAML mapping.']);
        }

        $problems = $this->validate($parsed);

        if ($problems !== []) {
            throw new CountryConfigException($file, $problems);
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private function validate(array $config): array
    {
        $problems = [];

        foreach (self::REQUIRED_SECTIONS as $section) {
            if (! isset($config[$section])) {
                $problems[] = "Missing required section '{$section}'.";
            }
        }

        if ($problems !== []) {
            return $problems;
        }

        $problems = [
            ...$this->validateCountry($config['country']),
            ...$this->validateUnits($config['units']),
            ...$this->validateLocations($config['locations']),
            ...$this->validateCanonicalItems($config['canonical_items']),
        ];

        return [
            ...$problems,
            ...$this->validateBasket($config['basket'], $config['canonical_items'], $config['units']),
        ];
    }

    /**
     * @param  mixed  $country
     * @return list<string>
     */
    private function validateCountry($country): array
    {
        if (! is_array($country)) {
            return ["Section 'country' must be a mapping."];
        }

        $problems = [];

        $code = $country['code'] ?? null;
        if (! is_string($code) || ! preg_match('/^[A-Za-z]{2}$/', $code)) {
            $problems[] = "country.code must be a two-letter ISO 3166-1 alpha-2 code, got '".var_export($code, true)."'.";
        }

        if (! isset($country['name']) || ! is_string($country['name'])) {
            $problems[] = 'country.name is required.';
        }

        $currency = $country['currency'] ?? null;
        if (! is_array($currency) || ! isset($currency['code']) || ! preg_match('/^[A-Za-z]{3}$/', (string) $currency['code'])) {
            $problems[] = 'country.currency.code must be a three-letter ISO 4217 code.';
        }

        // Not every currency has two decimal places; several use three.
        // Getting this wrong misprices every published figure, so it is
        // validated rather than defaulted silently.
        if (isset($currency['minor_units']) && ! is_int($currency['minor_units'])) {
            $problems[] = 'country.currency.minor_units must be an integer.';
        }

        $locales = $country['locales'] ?? null;
        if (! is_array($locales) || $locales === []) {
            $problems[] = 'country.locales must be a non-empty list.';
        } elseif (isset($country['default_locale']) && ! in_array($country['default_locale'], $locales, true)) {
            $problems[] = sprintf(
                "country.default_locale '%s' is not present in country.locales.",
                (string) $country['default_locale'],
            );
        }

        return $problems;
    }

    /**
     * @param  mixed  $units
     * @return list<string>
     */
    private function validateUnits($units): array
    {
        if (! is_array($units) || $units === []) {
            return ['Section "units" must be a non-empty list.'];
        }

        $problems = [];
        $codes = [];

        foreach ($units as $i => $unit) {
            if (! is_array($unit) || ! isset($unit['code'])) {
                $problems[] = "units[{$i}] is missing 'code'.";

                continue;
            }

            $code = (string) $unit['code'];
            if (in_array($code, $codes, true)) {
                $problems[] = "units[{$i}] duplicates code '{$code}'.";
            }
            $codes[] = $code;

            $factor = $unit['factor_to_base'] ?? null;
            if (! is_numeric($factor) || (float) $factor <= 0.0) {
                $problems[] = "units[{$i}] ('{$code}') factor_to_base must be a positive number.";
            }

            if (! isset($unit['base_unit_code'])) {
                $problems[] = "units[{$i}] ('{$code}') is missing 'base_unit_code'.";
            }
        }

        // A base unit that is not itself defined would make normalisation
        // silently impossible for everything in its dimension.
        foreach ($units as $i => $unit) {
            if (is_array($unit) && isset($unit['base_unit_code'])
                && ! in_array((string) $unit['base_unit_code'], $codes, true)) {
                $problems[] = sprintf(
                    "units[%d] references base_unit_code '%s', which is not defined.",
                    $i,
                    (string) $unit['base_unit_code'],
                );
            }
        }

        return $problems;
    }

    /**
     * @param  mixed  $locations
     * @return list<string>
     */
    private function validateLocations($locations): array
    {
        if (! is_array($locations) || $locations === []) {
            return ['Section "locations" must be a non-empty list.'];
        }

        $problems = [];
        $slugs = [];

        foreach ($locations as $i => $location) {
            if (! is_array($location)) {
                $problems[] = "locations[{$i}] must be a mapping.";

                continue;
            }

            foreach (['name', 'slug'] as $key) {
                if (! isset($location[$key]) || ! is_string($location[$key])) {
                    $problems[] = "locations[{$i}] is missing '{$key}'.";
                }
            }

            $slug = (string) ($location['slug'] ?? '');
            if ($slug !== '' && in_array($slug, $slugs, true)) {
                $problems[] = "locations[{$i}] duplicates slug '{$slug}'.";
            }
            $slugs[] = $slug;

            // Coordinates drive spatial imputation. They are optional, but a
            // half-specified pair is always a mistake.
            $hasLat = isset($location['latitude']);
            $hasLon = isset($location['longitude']);

            if ($hasLat !== $hasLon) {
                $problems[] = "locations[{$i}] ('{$slug}') has only one of latitude/longitude.";
            }

            if ($hasLat && (! is_numeric($location['latitude']) || abs((float) $location['latitude']) > 90)) {
                $problems[] = "locations[{$i}] ('{$slug}') latitude must be between -90 and 90.";
            }

            if ($hasLon && (! is_numeric($location['longitude']) || abs((float) $location['longitude']) > 180)) {
                $problems[] = "locations[{$i}] ('{$slug}') longitude must be between -180 and 180.";
            }
        }

        return $problems;
    }

    /**
     * @param  mixed  $items
     * @return list<string>
     */
    private function validateCanonicalItems($items): array
    {
        if (! is_array($items) || $items === []) {
            return ['Section "canonical_items" must be a non-empty list.'];
        }

        $problems = [];
        $codes = [];

        foreach ($items as $i => $item) {
            if (! is_array($item) || ! isset($item['code'])) {
                $problems[] = "canonical_items[{$i}] is missing 'code'.";

                continue;
            }

            $code = (string) $item['code'];
            if (in_array($code, $codes, true)) {
                $problems[] = "canonical_items[{$i}] duplicates code '{$code}'.";
            }
            $codes[] = $code;

            foreach (['name_en', 'category', 'default_unit_code'] as $key) {
                if (! isset($item[$key])) {
                    $problems[] = "canonical_items[{$i}] ('{$code}') is missing '{$key}'.";
                }
            }

            if (isset($item['variants']) && ! is_array($item['variants'])) {
                $problems[] = "canonical_items[{$i}] ('{$code}') variants must be a list.";
            }
        }

        return $problems;
    }

    /**
     * @param  mixed  $basket
     * @param  mixed  $canonicalItems
     * @param  mixed  $units
     * @return list<string>
     */
    private function validateBasket($basket, $canonicalItems, $units): array
    {
        if (! is_array($basket)) {
            return ['Section "basket" must be a mapping.'];
        }

        $problems = [];

        foreach (['name', 'version', 'effective_from', 'items'] as $key) {
            if (! isset($basket[$key])) {
                $problems[] = "basket.{$key} is required.";
            }
        }

        $items = $basket['items'] ?? null;
        if (! is_array($items) || $items === []) {
            return [...$problems, 'basket.items must be a non-empty list.'];
        }

        $itemCodes = is_array($canonicalItems)
            ? array_map(fn ($i): string => (string) ($i['code'] ?? ''), $canonicalItems)
            : [];
        $unitCodes = is_array($units)
            ? array_map(fn ($u): string => (string) ($u['code'] ?? ''), $units)
            : [];

        $weightSum = 0.0;
        $seen = [];

        foreach ($items as $i => $entry) {
            if (! is_array($entry) || ! isset($entry['item'])) {
                $problems[] = "basket.items[{$i}] is missing 'item'.";

                continue;
            }

            $code = (string) $entry['item'];

            if (! in_array($code, $itemCodes, true)) {
                $problems[] = "basket.items[{$i}] references unknown canonical item '{$code}'.";
            }

            if (in_array($code, $seen, true)) {
                $problems[] = "basket.items[{$i}] lists '{$code}' more than once.";
            }
            $seen[] = $code;

            if (isset($entry['unit']) && ! in_array((string) $entry['unit'], $unitCodes, true)) {
                $problems[] = sprintf(
                    "basket.items[%d] ('%s') uses unknown unit '%s'.",
                    $i,
                    $code,
                    (string) $entry['unit'],
                );
            }

            $weight = $entry['weight'] ?? null;
            if (! is_numeric($weight) || (float) $weight <= 0.0) {
                $problems[] = "basket.items[{$i}] ('{$code}') weight must be a positive number.";
            } else {
                $weightSum += (float) $weight;
            }

            $quantity = $entry['quantity'] ?? null;
            if (! is_numeric($quantity) || (float) $quantity <= 0.0) {
                $problems[] = "basket.items[{$i}] ('{$code}') quantity must be a positive number.";
            }
        }

        // The invariant that makes coverage meaningful. A basket summing to 0.8
        // would understate coverage by 20% on every snapshot, permanently and
        // silently, so it is rejected at load time.
        if (abs($weightSum - 1.0) > Basket::WEIGHT_SUM_TOLERANCE) {
            $problems[] = sprintf(
                'basket item weights must sum to 1.0, got %.6f (off by %+.6f).',
                $weightSum,
                $weightSum - 1.0,
            );
        }

        return $problems;
    }
}

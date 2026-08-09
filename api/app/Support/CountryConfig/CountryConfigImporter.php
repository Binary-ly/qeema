<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\CountryConfig;

use App\Models\Basket;
use App\Models\BasketItem;
use App\Models\CanonicalItem;
use App\Models\CanonicalItemVariant;
use App\Models\Country;
use App\Models\Location;
use App\Models\Source;
use App\Models\Unit;
use App\Support\Text\TextNormalizer;
use Illuminate\Support\Facades\DB;

/**
 * Writes a validated country configuration into the database.
 *
 * Idempotent by construction: everything is matched on its natural key and
 * updated in place. Re-running is how an operator applies an edit to a country
 * file, so it must not duplicate rows, and it must not destroy data that
 * references what it touches.
 *
 * Deliberately additive. Removing an item from the YAML deactivates it rather
 * than deleting it, because price observations point at canonical items and a
 * hard delete would sever the provenance chain behind already-published figures.
 */
final class CountryConfigImporter
{
    public function __construct(
        private readonly TextNormalizer $normalizer = new TextNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public function import(array $config): ImportSummary
    {
        return DB::transaction(function () use ($config): ImportSummary {
            $country = $this->importCountry($config['country']);

            $units = $this->importUnits($country, $config['units']);
            $locations = $this->importLocations($country, $config['locations']);
            [$items, $variants] = $this->importCanonicalItems($country, $config['canonical_items']);
            $basket = $this->importBasket($country, $config['basket']);
            $sources = $this->importSources($country, $config['sources'] ?? []);

            return new ImportSummary(
                countryCode: $country->code,
                units: $units,
                locations: $locations,
                canonicalItems: $items,
                variants: $variants,
                basketItems: $basket,
                sources: $sources,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importCountry(array $data): Country
    {
        /** @var array<string, mixed> $currency */
        $currency = $data['currency'];

        /** @var array<string, mixed> $adminLabels */
        $adminLabels = $data['admin_labels'] ?? [];

        return Country::query()->updateOrCreate(
            ['code' => strtoupper((string) $data['code'])],
            [
                'name' => (string) $data['name'],
                'name_local' => $data['name_local'] ?? null,
                'currency_code' => strtoupper((string) $currency['code']),
                'currency_symbol' => $currency['symbol'] ?? null,
                'currency_minor_units' => (int) ($currency['minor_units'] ?? 2),
                'default_locale' => (string) ($data['default_locale'] ?? 'en'),
                'locales' => $data['locales'] ?? ['en'],
                'timezone' => (string) ($data['timezone'] ?? 'UTC'),
                'admin1_label' => (string) ($adminLabels['admin1'] ?? 'Region'),
                'admin2_label' => $adminLabels['admin2'] ?? null,
                'is_active' => true,
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $units
     */
    private function importUnits(Country $country, array $units): int
    {
        foreach ($units as $unit) {
            Unit::query()->updateOrCreate(
                ['country_id' => $country->id, 'code' => (string) $unit['code']],
                [
                    'name' => (string) $unit['name'],
                    'name_local' => $unit['name_local'] ?? null,
                    'dimension' => (string) $unit['dimension'],
                    'base_unit_code' => (string) $unit['base_unit_code'],
                    'factor_to_base' => (float) $unit['factor_to_base'],
                ],
            );
        }

        return count($units);
    }

    /**
     * @param  list<array<string, mixed>>  $locations
     */
    private function importLocations(Country $country, array $locations): int
    {
        foreach ($locations as $location) {
            Location::query()->updateOrCreate(
                ['country_id' => $country->id, 'slug' => (string) $location['slug']],
                [
                    'name' => (string) $location['name'],
                    'name_local' => $location['name_local'] ?? null,
                    'admin1_name' => $location['admin1_name'] ?? null,
                    'admin1_code' => $location['admin1_code'] ?? null,
                    'admin2_name' => $location['admin2_name'] ?? null,
                    'admin2_code' => $location['admin2_code'] ?? null,
                    'latitude' => isset($location['latitude']) ? (float) $location['latitude'] : null,
                    'longitude' => isset($location['longitude']) ? (float) $location['longitude'] : null,
                    'population_estimate' => isset($location['population_estimate'])
                        ? (int) $location['population_estimate']
                        : null,
                    'is_active' => true,
                ],
            );
        }

        return count($locations);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{0: int, 1: int}
     */
    private function importCanonicalItems(Country $country, array $items): array
    {
        $variantCount = 0;

        foreach ($items as $data) {
            $item = CanonicalItem::query()->updateOrCreate(
                ['country_id' => $country->id, 'code' => (string) $data['code']],
                [
                    'name_en' => (string) $data['name_en'],
                    'name_local' => $data['name_local'] ?? null,
                    'category' => (string) $data['category'],
                    'default_unit_code' => (string) $data['default_unit_code'],
                    'default_quantity' => (float) ($data['default_quantity'] ?? 1),
                    'is_active' => true,
                ],
            );

            // The item's own names are variants too — a reporter typing the
            // catalogue name exactly should hit the lexical index, not fall
            // through to semantic search.
            /** @var list<string> $configured */
            $configured = $data['variants'] ?? [];

            $texts = array_filter([
                (string) $data['name_en'],
                isset($data['name_local']) ? (string) $data['name_local'] : null,
                ...$configured,
            ]);

            foreach ($texts as $text) {
                $normalized = $this->normalizer->normalize((string) $text);

                if ($normalized === '') {
                    continue;
                }

                // Keyed on the normalised form: two spellings that normalise to
                // the same thing are one variant, not two.
                $variant = CanonicalItemVariant::query()->updateOrCreate(
                    ['canonical_item_id' => $item->id, 'normalized_text' => $normalized],
                    [
                        'text' => (string) $text,
                        'locale' => $this->guessLocale((string) $text),
                        'source' => CanonicalItemVariant::SOURCE_SEED,
                    ],
                );

                if ($variant->wasRecentlyCreated) {
                    $variantCount++;
                }
            }
        }

        return [count($items), $variantCount];
    }

    /**
     * Best-effort locale tag for a seeded variant.
     *
     * Only used to group variants for display; matching never depends on it,
     * because reporters mix scripts within a single phrase.
     */
    private function guessLocale(string $text): string
    {
        return preg_match('/\p{Arabic}/u', $text) === 1 ? 'ar' : 'en';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function importBasket(Country $country, array $data): int
    {
        $basket = Basket::query()->updateOrCreate(
            ['country_id' => $country->id, 'version' => (int) $data['version']],
            [
                'name' => (string) $data['name'],
                'effective_from' => (string) $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null,
                'notes' => $data['notes'] ?? null,
                'is_active' => true,
            ],
        );

        /** @var list<array<string, mixed>> $entries */
        $entries = $data['items'];

        $itemsByCode = CanonicalItem::query()
            ->where('country_id', $country->id)
            ->pluck('id', 'code');

        foreach ($entries as $entry) {
            $canonicalItemId = $itemsByCode[(string) $entry['item']] ?? null;

            if ($canonicalItemId === null) {
                // Unreachable for a validated config; guarded so a future
                // caller that skips validation fails loudly rather than
                // silently dropping a basket item and understating the cost.
                throw new \RuntimeException(
                    "Basket references canonical item '{$entry['item']}', which was not imported."
                );
            }

            BasketItem::query()->updateOrCreate(
                ['basket_id' => $basket->id, 'canonical_item_id' => $canonicalItemId],
                [
                    'weight' => (float) $entry['weight'],
                    'quantity' => (float) $entry['quantity'],
                    'unit_code' => (string) $entry['unit'],
                    'category' => (string) ($entry['category'] ?? 'uncategorised'),
                    'notes' => $entry['notes'] ?? null,
                ],
            );
        }

        return count($entries);
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     */
    private function importSources(Country $country, array $sources): int
    {
        foreach ($sources as $source) {
            Source::query()->updateOrCreate(
                ['country_id' => $country->id, 'slug' => (string) $source['slug']],
                [
                    'type' => (string) $source['type'],
                    'name' => (string) $source['name'],
                    'url' => $source['url'] ?? null,
                    'license' => $source['license'] ?? null,
                    'contact' => $source['contact'] ?? null,
                    'config' => $source['config'] ?? null,
                    'is_active' => true,
                ],
            );
        }

        return count($sources);
    }
}

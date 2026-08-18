<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Country;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Country>
 */
final class CountryFactory extends Factory
{
    protected $model = Country::class;

    /** Monotonic source of collision-free two-letter country codes. */
    private static int $codeSequence = 0;

    /**
     * Deterministic two-letter code, cycling through AA..ZZ.
     *
     * Codes are fictional on purpose: a test that depended on a real country's
     * code would stop proving the platform is country-agnostic.
     */
    private static function nextCode(): string
    {
        // Skips codes already present. The sequence eventually reaches real ISO
        // codes, and a suite that seeds a country then generates enough factory
        // countries to wrap around to it fails on a unique constraint — with a
        // message that points nowhere near the actual cause.
        for ($attempt = 0; $attempt < 676; $attempt++) {
            $n = self::$codeSequence++ % 676;
            $code = chr(65 + intdiv($n, 26)).chr(65 + $n % 26);

            if (! Country::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Exhausted the two-letter country code space.');
    }

    /**
     * Give every factory country the units a real country file declares.
     *
     * Not a convenience. `countries/*.yaml` always defines a `units:` block,
     * and the importer creates those rows before any basket references them —
     * so a country without units is a state the application cannot reach.
     *
     * Fixtures did reach it, and that is how a dimensional bug survived. Basket
     * lines were created with `unit_code: 'kg'` against countries that had no
     * `kg`, the calculator never looked a unit up, and every costing test
     * therefore proved arithmetic the production code was not doing. A fixture
     * that cannot represent the failure cannot catch it.
     *
     * Base-unit factors are 1 by definition; the rest convert into them.
     */
    public function configure(): self
    {
        return $this->afterCreating(function (Country $country): void {
            foreach ([
                ['kg', 'Kilogram', 'mass', 'kg', 1.0],
                ['g', 'Gram', 'mass', 'kg', 0.001],
                ['l', 'Litre', 'volume', 'l', 1.0],
                ['ml', 'Millilitre', 'volume', 'l', 0.001],
                ['piece', 'Piece', 'count', 'piece', 1.0],
                ['pack', 'Pack', 'count', 'piece', 1.0],
                ['dozen', 'Dozen', 'count', 'piece', 12.0],
            ] as [$code, $name, $dimension, $base, $factor]) {
                Unit::query()->firstOrCreate(
                    ['country_id' => $country->id, 'code' => $code],
                    [
                        'name' => $name,
                        'dimension' => $dimension,
                        'base_unit_code' => $base,
                        'factor_to_base' => $factor,
                    ],
                );
            }
        });
    }

    /**
     * A deliberately fictional country.
     *
     * Tests must never depend on a real country's configuration, or they stop
     * proving that the platform is country-agnostic and start encoding one
     * country's assumptions into the test suite.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // A counter rather than random letters: two-letter codes have only
            // 676 combinations, and a test that creates several countries hit
            // birthday collisions often enough to make the suite flaky.
            'code' => self::nextCode(),
            'name' => $this->faker->unique()->country(),
            'name_local' => null,
            'currency_code' => strtoupper($this->faker->lexify('???')),
            'currency_symbol' => '¤',
            'currency_minor_units' => 2,
            'default_locale' => 'en',
            'locales' => ['en'],
            'timezone' => 'UTC',
            'admin1_label' => 'Region',
            'admin2_label' => 'District',
            'fx_config' => [
                'provider' => 'manual',
                'base_currency' => 'USD',
                'rate_type' => 'parallel',
                'max_staleness_days' => 7,
            ],
            'index_config' => Country::INDEX_DEFAULTS,
            'is_active' => true,
        ];
    }

    /** A country whose UI runs right-to-left, for RTL regression tests. */
    public function rightToLeft(): self
    {
        return $this->state(fn (): array => [
            'default_locale' => 'ar',
            'locales' => ['ar', 'en'],
            'currency_minor_units' => 3,
        ]);
    }

    /** A country configured to convert at the official rather than street rate. */
    public function usingOfficialRate(): self
    {
        return $this->state(fn (array $attributes): array => [
            'fx_config' => [...$attributes['fx_config'], 'rate_type' => 'official'],
        ]);
    }
}

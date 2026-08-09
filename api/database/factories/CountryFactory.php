<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Country;
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
        $n = self::$codeSequence++ % 676;

        return chr(65 + intdiv($n, 26)).chr(65 + $n % 26);
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

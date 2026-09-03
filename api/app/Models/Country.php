<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A deployment's country configuration.
 *
 * Everything that differs between deployments hangs off this model. Code that
 * needs a currency, a locale or an FX provider asks a Country; it never assumes
 * one (constraint C3).
 *
 * The JSON columns are declared here because Larastan reads the column type
 * rather than `casts()`, and would otherwise treat them as strings — which
 * turns every read of a nested key into an error nobody can act on.
 *
 * @property array<string, mixed>|null $fx_config
 * @property array<string, mixed>|null $index_config
 * @property list<string> $locales
 */
final class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use HasFactory;

    protected $fillable = [
        'code', 'name', 'name_local',
        'currency_code', 'currency_symbol', 'currency_minor_units',
        'default_locale', 'locales', 'timezone',
        'admin1_label', 'admin2_label',
        'fx_config', 'index_config', 'reference_income', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'locales' => 'array',
            'fx_config' => 'array',
            'index_config' => 'array',
            'reference_income' => 'array',
            'is_active' => 'boolean',
            'currency_minor_units' => 'integer',
        ];
    }

    /**
     * The sourced monthly income the basket is set against, or null.
     *
     * A cost is a number. A cost as a share of the legal minimum wage is a
     * sentence somebody can act on. The figure comes from the country file
     * with its citation, and a country that declares none gets no comparison
     * rather than a guessed one.
     *
     * @return array{amount: float, period: string, label_en: string, label_local: string|null, sources: list<array<string, mixed>>}|null
     */
    public function referenceIncome(): ?array
    {
        /** @var array<string, mixed> $raw */
        $raw = $this->reference_income ?? [];
        $amount = (float) ($raw['amount'] ?? 0);

        if ($amount <= 0.0 || ! isset($raw['label_en'])) {
            return null;
        }

        /** @var list<array<string, mixed>> $sources */
        $sources = is_array($raw['sources'] ?? null) ? array_values($raw['sources']) : [];

        return [
            'amount' => $amount,
            'period' => (string) ($raw['period'] ?? 'month'),
            'label_en' => (string) $raw['label_en'],
            'label_local' => isset($raw['label_local']) ? (string) $raw['label_local'] : null,
            'sources' => $sources,
        ];
    }

    /**
     * Defaults for the index estimator, merged over whatever the country config
     * supplies. Centralised here so a country that omits a key still computes,
     * rather than failing deep inside the estimator.
     */
    public const INDEX_DEFAULTS = [
        'observation_window_days' => 7,
        'recency_half_life_days' => 3,
        'min_observations_for_ci' => 3,
        'bootstrap_draws' => 500,
        'base_date' => null,
        // How far back the nowcast draws its training rows. 120 days is four
        // points per series from a monthly source, which is why the live
        // deployment's model declined to train on 116 rows while a year of
        // the same source held over a thousand.
        'nowcast_training_days' => 120,
    ];

    /**
     * @return array{observation_window_days:int, recency_half_life_days:int, min_observations_for_ci:int, bootstrap_draws:int, base_date:string|null, nowcast_training_days:int}
     */
    public function indexSettings(): array
    {
        /** @var array<string, mixed> $configured */
        $configured = $this->index_config ?? [];

        /** @var array{observation_window_days:int, recency_half_life_days:int, min_observations_for_ci:int, bootstrap_draws:int, base_date:string|null, nowcast_training_days:int} $merged */
        $merged = array_merge(self::INDEX_DEFAULTS, $configured);

        return $merged;
    }

    public function fxProvider(): string
    {
        /** @var array<string, mixed> $fx */
        $fx = $this->fx_config ?? [];

        return (string) ($fx['provider'] ?? 'manual');
    }

    /**
     * Which rate the index converts with. Defaults to the parallel rate because
     * in a crisis economy the official rate describes a transaction most people
     * cannot actually make.
     */
    public function fxRateType(): string
    {
        /** @var array<string, mixed> $fx */
        $fx = $this->fx_config ?? [];

        return (string) ($fx['rate_type'] ?? 'parallel');
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /** @return HasMany<CanonicalItem, $this> */
    public function canonicalItems(): HasMany
    {
        return $this->hasMany(CanonicalItem::class);
    }

    /** @return HasMany<Basket, $this> */
    public function baskets(): HasMany
    {
        return $this->hasMany(Basket::class);
    }

    /** @return HasMany<Unit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /** @return HasMany<Source, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(Source::class);
    }

    /** @return HasMany<FxRate, $this> */
    public function fxRates(): HasMany
    {
        return $this->hasMany(FxRate::class);
    }

    /**
     * The basket version in force on a given date.
     *
     * Historical snapshots must be costed against the basket that was actually
     * in force, not today's definition.
     */
    public function basketOn(\DateTimeInterface $date): ?Basket
    {
        return $this->baskets()
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date);
            })
            ->orderByDesc('version')
            ->first();
    }
}

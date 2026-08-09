<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CanonicalItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

/**
 * An entry in the controlled vocabulary that raw text resolves to.
 *
 * The embedding is the semantic half of the matcher. It is built from the item's
 * names *and* its known variants, so that when a human review adds a new way of
 * spelling something, re-embedding actually improves future retrieval rather
 * than leaving the vector unchanged.
 */
final class CanonicalItem extends Model
{
    /** @use HasFactory<CanonicalItemFactory> */
    use HasFactory;

    use HasNeighbors;

    protected $fillable = [
        'country_id', 'code', 'name_en', 'name_local', 'category',
        'default_unit_code', 'default_quantity',
        'embedding', 'embedding_model', 'embedding_updated_at', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => Vector::class,
            'embedding_updated_at' => 'datetime',
            'default_quantity' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return HasMany<CanonicalItemVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(CanonicalItemVariant::class);
    }

    /** @return HasMany<PriceObservation, $this> */
    public function priceObservations(): HasMany
    {
        return $this->hasMany(PriceObservation::class);
    }

    /**
     * The text that gets embedded.
     *
     * e5 models expect a "passage: " prefix on the indexed side; that prefix is
     * added by the ML service from configuration, so it is deliberately absent
     * here. This method returns content only.
     */
    public function embeddableText(): string
    {
        $parts = array_filter([
            $this->name_en,
            $this->name_local,
            ...$this->variants->pluck('text')->all(),
        ]);

        return implode(' | ', array_unique($parts));
    }

    /** True when the stored vector predates the current model or is absent. */
    public function needsEmbedding(string $currentModel): bool
    {
        return $this->embedding === null || $this->embedding_model !== $currentModel;
    }
}

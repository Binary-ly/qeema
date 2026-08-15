<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phrase a reviewer has ruled is not a product this deployment tracks.
 *
 * The mirror of {@see CanonicalItemVariant}: one teaches the matcher what a
 * phrase means, this teaches it that a phrase means nothing. Both exist so a
 * human decision is made once rather than once per submission.
 *
 * @property int $id
 * @property int $country_id
 * @property string $text
 * @property string $normalized_text
 * @property string|null $reason
 * @property int $times_matched
 */
final class UnmatchablePhrase extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'times_matched' => 'integer',
            'last_matched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeForCountry(Builder $query, int $countryId): void
    {
        $query->where('country_id', $countryId);
    }

    /**
     * Record that this ruling saved a reviewer another decision.
     *
     * Kept as a counter rather than derived, so an operator auditing these can
     * see which rulings earn their keep and which were a one-off worth deleting.
     */
    public function recordMatch(): void
    {
        $this->forceFill([
            'times_matched' => $this->times_matched + 1,
            'last_matched_at' => now(),
        ])->save();
    }
}

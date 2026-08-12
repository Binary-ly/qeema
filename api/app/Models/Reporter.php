<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ReporterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone submitting prices, and how far the system trusts them.
 *
 * Reputation is the mean of a Beta posterior. See the migration for why a raw
 * accepted/total ratio is not good enough.
 */
final class Reporter extends Model
{
    /** @use HasFactory<ReporterFactory> */
    use HasFactory;

    /** Uninformative prior: everyone starts at 0.5 with genuine uncertainty. */
    public const PRIOR_ALPHA = 2.0;

    public const PRIOR_BETA = 2.0;

    /**
     * Floor applied when reputation is used as an estimator weight.
     *
     * Without it, a reporter whose early submissions were rejected gets weighted
     * towards zero, deviates further from an estimate they no longer influence,
     * and can never climb back. The floor keeps a recovery path open.
     */
    public const WEIGHT_FLOOR = 0.25;

    protected $fillable = [
        'country_id', 'location_id', 'external_ref', 'display_name',
        'reputation', 'reputation_alpha', 'reputation_beta',
        'submissions_total', 'submissions_accepted', 'submissions_rejected',
        'first_seen_at', 'last_seen_at', 'is_blocked', 'blocked_reason',
        'bias_score', 'bias_flagged', 'bias_reason', 'bias_checked_at', 'bias_cleared_at',
    ];

    protected function casts(): array
    {
        return [
            'reputation' => 'float',
            'reputation_alpha' => 'float',
            'reputation_beta' => 'float',
            'submissions_total' => 'integer',
            'submissions_accepted' => 'integer',
            'submissions_rejected' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_blocked' => 'boolean',
            'bias_score' => 'float',
            'bias_flagged' => 'boolean',
            'bias_checked_at' => 'datetime',
            'bias_cleared_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return HasMany<Submission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /**
     * Flagged by the detector and not yet looked at by a person.
     *
     * A queue an operator works, and deliberately not something the platform
     * acts on by itself: what the detector produces is a reason to look, not a
     * verdict. `is_blocked` is set by a human or not at all.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingBiasReview(Builder $query): void
    {
        $query->where('bias_flagged', true)->whereNull('bias_cleared_at');
    }

    /**
     * Record what the detector found, without acting on it.
     */
    public function recordBias(?float $score, bool $flagged, ?string $reason): void
    {
        $this->forceFill([
            'bias_score' => $score,
            'bias_flagged' => $flagged,
            'bias_reason' => $reason,
            'bias_checked_at' => now(),
            // A reporter who stops looking suspicious is cleared automatically;
            // one who still does keeps whatever a human already decided, so a
            // reporter somebody has already cleared is not re-raised nightly
            // until the flag means nothing.
            'bias_cleared_at' => $flagged ? $this->bias_cleared_at : null,
        ])->save();
    }

    /**
     * Update the posterior from a human-confirmed verdict.
     *
     * Only human-confirmed outcomes are passed here. Letting the automated
     * anomaly score feed reputation would create a loop where the detector
     * progressively silences whoever it first suspected.
     */
    public function recordConfirmedVerdict(bool $accepted): void
    {
        if ($accepted) {
            $this->reputation_alpha += 1;
            $this->submissions_accepted += 1;
        } else {
            $this->reputation_beta += 1;
            $this->submissions_rejected += 1;
        }

        $this->reputation = $this->posteriorMean();
        $this->save();
    }

    public function posteriorMean(): float
    {
        $total = $this->reputation_alpha + $this->reputation_beta;

        return $total > 0 ? $this->reputation_alpha / $total : 0.5;
    }

    /** Reputation as the estimator should use it, floored to allow recovery. */
    public function weight(): float
    {
        return max(self::WEIGHT_FLOOR, $this->reputation);
    }
}

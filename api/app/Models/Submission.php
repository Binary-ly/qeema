<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\SubmissionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The raw inbound observation — the root of every provenance chain.
 *
 * Nothing here is ever rewritten. `raw_text` in particular keeps the original
 * spelling, dialect and script exactly as submitted, because it is both the
 * audit trail and the training signal for the matcher.
 *
 * The cast attributes are declared explicitly because Larastan cannot infer
 * types from the casts() method and would otherwise treat these as strings.
 *
 * @property CarbonInterface|null $observed_at
 * @property CarbonInterface|null $collected_at
 * @property CarbonInterface|null $ingested_at
 * @property array<string, mixed>|null $device_metadata
 * @property int $pipeline_attempts
 * @property CarbonInterface|null $pipeline_attempted_at
 * @property string|null $pipeline_last_error
 */
final class Submission extends Model
{
    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'country_id', 'location_id', 'reporter_id', 'source_id', 'ingestion_batch_id',
        'raw_text', 'raw_price', 'currency_code', 'raw_unit', 'raw_quantity',
        'photo_path', 'observed_at', 'collected_at', 'ingested_at',
        'device_metadata', 'client_idempotency_key', 'status',
        'pipeline_attempts', 'pipeline_attempted_at', 'pipeline_last_error',
    ];

    protected function casts(): array
    {
        return [
            'raw_price' => 'decimal:4',
            'raw_quantity' => 'decimal:4',
            'observed_at' => 'datetime',
            'collected_at' => 'datetime',
            'ingested_at' => 'datetime',
            'device_metadata' => 'array',
            'pipeline_attempts' => 'integer',
            'pipeline_attempted_at' => 'datetime',
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

    /** @return BelongsTo<Reporter, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(Reporter::class);
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return BelongsTo<IngestionBatch, $this> */
    public function ingestionBatch(): BelongsTo
    {
        return $this->belongsTo(IngestionBatch::class);
    }

    /** @return HasOne<Resolution, $this> */
    public function resolution(): HasOne
    {
        return $this->hasOne(Resolution::class);
    }

    /** @return HasOne<PriceObservation, $this> */
    public function priceObservation(): HasOne
    {
        return $this->hasOne(PriceObservation::class);
    }

    /** @return HasMany<AnomalyScore, $this> */
    public function anomalyScores(): HasMany
    {
        return $this->hasMany(AnomalyScore::class);
    }

    /**
     * The verdict that currently stands.
     *
     * A submission can be scored more than once — a rejection overruled, a
     * detector retrained — and the review queue must show what the screening
     * layer thinks *now*, not what it thought first.
     *
     * @return HasOne<AnomalyScore, $this>
     */
    public function latestAnomalyScore(): HasOne
    {
        return $this->hasOne(AnomalyScore::class)->latestOfMany();
    }

    /**
     * How long this submission sat on a device before reaching the server.
     *
     * Useful for spotting a reporter whose queue never drains, and for judging
     * whether an observation is stale enough to discount.
     */
    public function syncLagSeconds(): ?int
    {
        if ($this->collected_at === null || $this->ingested_at === null) {
            return null;
        }

        // Carbon 3 returns a float from diff methods; the caller wants whole seconds.
        return (int) $this->ingested_at->diffInSeconds($this->collected_at, absolute: true);
    }

    public function wasSubmittedOffline(): bool
    {
        /** @var array<string, mixed> $meta */
        $meta = $this->device_metadata ?? [];

        return (bool) ($meta['queued_offline'] ?? false);
    }

    /** @param Builder<self> $query */
    public function scopeAwaitingReview(Builder $query): void
    {
        $query->where('status', self::STATUS_NEEDS_REVIEW);
    }

    /**
     * Submissions the pipeline has not finished with.
     *
     * @param  Builder<self>  $query
     */
    public function scopeAwaitingPipeline(Builder $query): void
    {
        $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Count one attempt at automatic resolution, recording why it failed.
     *
     * Written outside any transaction the caller may own: the whole purpose of
     * the counter is to survive the failure being recorded, and a rollback that
     * also erased the evidence would produce a submission that retries forever
     * while claiming it has never been tried.
     */
    public function recordPipelineAttempt(?string $error = null): void
    {
        $this->forceFill([
            'pipeline_attempts' => $this->pipeline_attempts + 1,
            'pipeline_attempted_at' => now(),
            'pipeline_last_error' => $error,
        ])->save();
    }

    /**
     * Has automatic resolution used up its budget?
     */
    public function pipelineBudgetExhausted(): bool
    {
        return $this->pipeline_attempts >= (int) config('qeema.pipeline.max_attempts');
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ResolutionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property list<array<string, mixed>>|null $candidates
 */
final class Resolution extends Model
{
    /** @use HasFactory<ResolutionFactory> */
    use HasFactory;

    public const METHOD_EXACT = 'exact';

    public const METHOD_LEXICAL = 'lexical';

    public const METHOD_SEMANTIC = 'semantic';

    public const METHOD_FUSED = 'fused';

    public const METHOD_HUMAN = 'human';

    public const METHOD_RULE = 'rule';

    protected $fillable = [
        'submission_id', 'canonical_item_id', 'method', 'confidence',
        'candidates', 'reviewed', 'reviewed_by_user_id', 'reviewed_at', 'model_version',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'candidates' => 'array',
            'reviewed' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /** @return BelongsTo<CanonicalItem, $this> */
    public function canonicalItem(): BelongsTo
    {
        return $this->belongsTo(CanonicalItem::class);
    }

    /**
     * Where the correct answer sat in the retrieved candidates, if at all.
     *
     * The distinction matters: a correct answer ranked third is a ranking
     * problem, while one that was never retrieved is a recall problem. They call
     * for different fixes, so the review UI surfaces this.
     *
     * @return int|null 1-based rank, or null when it was not retrieved
     */
    public function rankOfCorrectAnswer(int $correctCanonicalItemId): ?int
    {
        /** @var list<array<string, mixed>> $candidates */
        $candidates = $this->candidates ?? [];

        foreach ($candidates as $i => $candidate) {
            if ((int) ($candidate['canonical_item_id'] ?? 0) === $correctCanonicalItemId) {
                return $i + 1;
            }
        }

        return null;
    }

    public function wasAutoResolved(): bool
    {
        return ! $this->reviewed && $this->method !== self::METHOD_HUMAN;
    }

    /** @param Builder<self> $query */
    public function scopeUnreviewed(Builder $query): void
    {
        $query->where('reviewed', false);
    }
}

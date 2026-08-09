<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\AnomalyScoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AnomalyScore extends Model
{
    /** @use HasFactory<AnomalyScoreFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const VERDICT_CLEAN = 'clean';

    public const VERDICT_SUSPECT = 'suspect';

    public const VERDICT_REJECTED = 'rejected';

    protected $fillable = [
        'submission_id', 'score', 'verdict', 'reasons', 'layer_scores', 'model_version',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'reasons' => 'array',
            'layer_scores' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Submission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    /**
     * Human-readable explanations for a reviewer.
     *
     * A reviewer who cannot see why something was flagged will either
     * rubber-stamp it or ignore it, and both defeat the point of the queue.
     *
     * @return list<string>
     */
    public function reasonMessages(): array
    {
        /** @var list<array<string, mixed>|string> $reasons */
        $reasons = $this->reasons ?? [];

        return array_map(
            fn (array|string $r): string => is_string($r) ? $r : (string) ($r['message'] ?? ''),
            $reasons
        );
    }

    public function isActionable(): bool
    {
        return $this->verdict !== self::VERDICT_CLEAN;
    }
}

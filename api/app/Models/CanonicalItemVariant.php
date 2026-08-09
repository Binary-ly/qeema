<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CanonicalItemVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An alternative name the matcher has learned for a canonical item.
 *
 * Variants created by `human_review` are the system's feedback loop: every time
 * a reviewer corrects a match, the phrase that confused the matcher becomes a
 * variant, so the same phrase resolves automatically next time.
 */
final class CanonicalItemVariant extends Model
{
    /** @use HasFactory<CanonicalItemVariantFactory> */
    use HasFactory;

    public const SOURCE_SEED = 'seed';

    public const SOURCE_HUMAN_REVIEW = 'human_review';

    public const SOURCE_SCRAPER = 'scraper';

    public const SOURCE_PARTNER = 'partner';

    protected $fillable = [
        'canonical_item_id', 'text', 'normalized_text', 'locale', 'source',
        'created_from_submission_id', 'created_by_user_id', 'times_matched',
    ];

    protected function casts(): array
    {
        return ['times_matched' => 'integer'];
    }

    /** @return BelongsTo<CanonicalItem, $this> */
    public function canonicalItem(): BelongsTo
    {
        return $this->belongsTo(CanonicalItem::class);
    }

    /** @return BelongsTo<Submission, $this> */
    public function createdFromSubmission(): BelongsTo
    {
        return $this->belongsTo(Submission::class, 'created_from_submission_id');
    }

    public function recordMatch(): void
    {
        $this->increment('times_matched');
    }
}

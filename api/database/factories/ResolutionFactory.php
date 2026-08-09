<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CanonicalItem;
use App\Models\Resolution;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Resolution>
 */
final class ResolutionFactory extends Factory
{
    protected $model = Resolution::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'canonical_item_id' => CanonicalItem::factory(),
            'method' => Resolution::METHOD_FUSED,
            'confidence' => 0.92,
            'candidates' => null,
            'reviewed' => false,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'model_version' => 'matcher-0.1.0',
        ];
    }

    /** Confident enough to auto-resolve without a human. */
    public function autoResolved(float $confidence = 0.94): self
    {
        return $this->state(fn (): array => [
            'confidence' => $confidence,
            'reviewed' => false,
            'method' => Resolution::METHOD_FUSED,
        ]);
    }

    /** Below threshold, so routed to the review queue. */
    public function lowConfidence(float $confidence = 0.41): self
    {
        return $this->state(fn (): array => [
            'confidence' => $confidence,
            'reviewed' => false,
        ]);
    }

    public function humanReviewed(): self
    {
        return $this->state(fn (): array => [
            'method' => Resolution::METHOD_HUMAN,
            'confidence' => 1.0,
            'reviewed' => true,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Populate the candidate list.
     *
     * @param  list<array{canonical_item_id:int, score:float}>  $candidates
     */
    public function withCandidates(array $candidates): self
    {
        return $this->state(fn (): array => ['candidates' => $candidates]);
    }
}

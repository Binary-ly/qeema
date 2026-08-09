<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CanonicalItem;
use App\Models\CanonicalItemVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CanonicalItemVariant>
 */
final class CanonicalItemVariantFactory extends Factory
{
    protected $model = CanonicalItemVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $text = $this->faker->unique()->words(2, true);

        return [
            'canonical_item_id' => CanonicalItem::factory(),
            'text' => $text,
            'normalized_text' => mb_strtolower((string) $text),
            'locale' => 'en',
            'source' => CanonicalItemVariant::SOURCE_SEED,
            'created_from_submission_id' => null,
            'created_by_user_id' => null,
            'times_matched' => 0,
        ];
    }

    /**
     * A variant that a reviewer created by correcting a bad match.
     *
     * This is the state that matters most: it is how the matcher learns, so
     * tests of the feedback loop depend on it being distinguishable from seed
     * data.
     */
    public function fromHumanReview(): self
    {
        return $this->state(fn (): array => [
            'source' => CanonicalItemVariant::SOURCE_HUMAN_REVIEW,
        ]);
    }

    /** Arabic text that is *not* normalised, to exercise the normaliser. */
    public function arabicUnnormalised(): self
    {
        return $this->state(fn (): array => [
            // Hamza on alef, taa marbuta and Arabic-Indic digits all present:
            // every one of these must be folded before matching.
            'text' => 'حليب أطفال ٤٠٠ غرام',
            'normalized_text' => 'حليب اطفال 400 غرام',
            'locale' => 'ar',
        ]);
    }
}

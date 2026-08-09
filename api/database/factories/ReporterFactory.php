<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Country;
use App\Models\Reporter;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Reporter>
 */
final class ReporterFactory extends Factory
{
    protected $model = Reporter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'location_id' => null,
            'external_ref' => Str::uuid()->toString(),
            'display_name' => null,
            'reputation' => 0.5,
            'reputation_alpha' => Reporter::PRIOR_ALPHA,
            'reputation_beta' => Reporter::PRIOR_BETA,
            'submissions_total' => 0,
            'submissions_accepted' => 0,
            'submissions_rejected' => 0,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'is_blocked' => false,
            'blocked_reason' => null,
        ];
    }

    /** Long track record of accepted submissions. */
    public function trusted(): self
    {
        return $this->state(fn (): array => [
            'reputation_alpha' => 102.0,
            'reputation_beta' => 3.0,
            'reputation' => 102 / 105,
            'submissions_total' => 105,
            'submissions_accepted' => 100,
            'submissions_rejected' => 1,
        ]);
    }

    /**
     * A reporter with a poor record.
     *
     * Used to prove the weight floor works: even here the estimator must not
     * weight them to zero, or they can never recover.
     */
    public function unreliable(): self
    {
        return $this->state(fn (): array => [
            'reputation_alpha' => 3.0,
            'reputation_beta' => 40.0,
            'reputation' => 3 / 43,
            'submissions_total' => 45,
            'submissions_accepted' => 1,
            'submissions_rejected' => 38,
        ]);
    }

    /** Brand new: prior only, no evidence either way. */
    public function coldStart(): self
    {
        return $this->state(fn (): array => [
            'reputation_alpha' => Reporter::PRIOR_ALPHA,
            'reputation_beta' => Reporter::PRIOR_BETA,
            'reputation' => 0.5,
            'submissions_total' => 0,
            'first_seen_at' => now(),
        ]);
    }

    public function blocked(string $reason = 'Coordinated manipulation'): self
    {
        return $this->state(fn (): array => [
            'is_blocked' => true,
            'blocked_reason' => $reason,
        ]);
    }
}

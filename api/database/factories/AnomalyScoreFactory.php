<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AnomalyScore;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnomalyScore>
 */
final class AnomalyScoreFactory extends Factory
{
    protected $model = AnomalyScore::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'submission_id' => Submission::factory(),
            'score' => 0.05,
            'verdict' => AnomalyScore::VERDICT_CLEAN,
            'reasons' => [],
            'layer_scores' => ['bounds' => 0.0, 'robust_stats' => 0.05, 'isolation_forest' => 0.04],
            'model_version' => 'anomaly-0.1.0',
        ];
    }

    /** Flagged, with an explanation a reviewer can actually act on. */
    public function suspect(string $message = 'Price is 8.2x the local median for this item'): self
    {
        return $this->state(fn (): array => [
            'score' => 0.72,
            'verdict' => AnomalyScore::VERDICT_SUSPECT,
            'reasons' => [['code' => 'robust_outlier', 'message' => $message]],
            'layer_scores' => ['bounds' => 0.0, 'robust_stats' => 0.88, 'isolation_forest' => 0.55],
        ]);
    }

    /** Outside the hard bounds; rejected before it can reach the index. */
    public function rejected(): self
    {
        return $this->state(fn (): array => [
            'score' => 0.98,
            'verdict' => AnomalyScore::VERDICT_REJECTED,
            'reasons' => [['code' => 'hard_bounds', 'message' => 'Price is outside the plausible range for this item']],
            'layer_scores' => ['bounds' => 1.0, 'robust_stats' => 0.97, 'isolation_forest' => 0.9],
        ]);
    }
}

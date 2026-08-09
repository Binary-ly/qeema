<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IngestionBatch;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IngestionBatch>
 */
final class IngestionBatchFactory extends Factory
{
    protected $model = IngestionBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_id' => Source::factory()->partnerUpload(),
            'uploaded_by_user_id' => null,
            'filename' => $this->faker->word().'.xlsx',
            'checksum' => hash('sha256', Str::uuid()->toString()),
            'row_count' => 100,
            'accepted_count' => 100,
            'rejected_count' => 0,
            'status' => IngestionBatch::STATUS_COMPLETED,
            'column_mapping' => [
                'item' => 'Product',
                'price' => 'Price',
                'unit' => 'Unit',
                'location' => 'Town',
                'observed_at' => 'Date',
            ],
            'error_report' => null,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }

    /**
     * Partial success — the normal outcome for a real partner file.
     *
     * A file with some bad rows must import the good ones and hand back an
     * actionable list, not reject the whole upload.
     */
    public function partiallyRejected(int $rejected = 12): self
    {
        return $this->state(fn (array $attributes): array => [
            'accepted_count' => $attributes['row_count'] - $rejected,
            'rejected_count' => $rejected,
            'error_report' => [
                'rows' => array_map(fn (int $i): array => [
                    'row' => $i + 2,
                    'column' => 'Price',
                    'message' => 'Price is not a number',
                ], range(0, $rejected - 1)),
            ],
        ]);
    }

    public function failed(string $message = 'Unreadable spreadsheet'): self
    {
        return $this->state(fn (): array => [
            'status' => IngestionBatch::STATUS_FAILED,
            'accepted_count' => 0,
            'rejected_count' => 0,
            'error_report' => ['fatal' => $message, 'rows' => []],
        ]);
    }
}

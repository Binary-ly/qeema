<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Country;
use App\Models\Location;
use App\Models\Reporter;
use App\Models\Source;
use App\Models\Submission;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Submission>
 */
final class SubmissionFactory extends Factory
{
    protected $model = Submission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $observedAt = $this->faker->dateTimeBetween('-14 days', 'now');

        return [
            'country_id' => Country::factory(),
            'location_id' => Location::factory(),
            'reporter_id' => Reporter::factory(),
            'source_id' => Source::factory(),
            'ingestion_batch_id' => null,
            'raw_text' => $this->faker->words(2, true),
            'raw_price' => $this->faker->randomFloat(2, 1, 500),
            'currency_code' => 'XTS',
            'raw_unit' => 'kg',
            'raw_quantity' => 1,
            'photo_path' => null,
            'observed_at' => $observedAt,
            'collected_at' => $observedAt,
            'ingested_at' => $observedAt,
            'device_metadata' => ['app_version' => '0.1.0', 'queued_offline' => false],
            'client_idempotency_key' => Str::uuid()->toString(),
            'status' => Submission::STATUS_PENDING,
        ];
    }

    /**
     * Captured offline and synced later.
     *
     * The gap between collected_at and ingested_at is the whole point: it proves
     * the index buckets by observation date rather than arrival date, which is
     * what stops a week of synced backlog landing on today.
     */
    public function syncedLate(int $daysLate = 3): self
    {
        return $this->state(function (array $attributes) use ($daysLate): array {
            $collected = CarbonImmutable::parse($attributes['collected_at']);

            return [
                'ingested_at' => $collected->addDays($daysLate),
                'device_metadata' => ['app_version' => '0.1.0', 'queued_offline' => true],
            ];
        });
    }

    /** Arabic free text with the noise a real reporter would produce. */
    public function withArabicText(): self
    {
        return $this->state(fn (): array => [
            'raw_text' => $this->faker->randomElement([
                'حليب أطفال ٤٠٠ غرام',
                'ارز ابيض كيلو',
                'زيت طعام ١ لتر',
                'دفتر مدرسي',
            ]),
        ]);
    }

    public function needingReview(): self
    {
        return $this->state(fn (): array => ['status' => Submission::STATUS_NEEDS_REVIEW]);
    }

    public function resolved(): self
    {
        return $this->state(fn (): array => ['status' => Submission::STATUS_RESOLVED]);
    }
}

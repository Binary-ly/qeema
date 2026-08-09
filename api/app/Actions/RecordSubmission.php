<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Actions;

use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\Reporter;
use App\Models\Source;
use App\Models\Submission;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Records an inbound price submission.
 *
 * The whole design of this class is about one problem: a reporter on a bad
 * connection will send the same submission more than once, and every duplicate
 * that lands is a real distortion of a published number. So the idempotency key
 * is enforced by a database constraint rather than a read-then-write check,
 * which would race under concurrent replay.
 */
final class RecordSubmission
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(array $input): SubmissionResult
    {
        $country = Country::query()->where('code', strtoupper((string) $input['country']))->firstOrFail();

        $location = Location::query()
            ->where('country_id', $country->id)
            ->where('slug', (string) $input['location_slug'])
            ->firstOrFail();

        $reporter = $this->resolveReporter($country, $location, (string) $input['reporter_ref']);

        if ($reporter->is_blocked) {
            return SubmissionResult::rejected('This reporter is blocked.');
        }

        $existing = Submission::query()
            ->where('reporter_id', $reporter->id)
            ->where('client_idempotency_key', (string) $input['client_idempotency_key'])
            ->first();

        // Fast path for the common replay case, so a phone flushing a queue
        // does not generate a constraint violation per already-synced item.
        if ($existing !== null) {
            return SubmissionResult::duplicate($existing);
        }

        $source = $this->reporterSource($country);
        $observedAt = isset($input['observed_at'])
            ? CarbonImmutable::parse((string) $input['observed_at'])
            : CarbonImmutable::now();

        /** @var array<string, mixed> $device */
        $device = $input['device'] ?? [];

        try {
            $submission = DB::transaction(function () use (
                $country, $location, $reporter, $source, $input, $observedAt, $device
            ): Submission {
                $submission = Submission::query()->create([
                    'country_id' => $country->id,
                    'location_id' => $location->id,
                    'reporter_id' => $reporter->id,
                    'source_id' => $source->id,
                    'raw_text' => $this->rawText($country, $input),
                    'raw_price' => (float) $input['price'],
                    'currency_code' => strtoupper((string) ($input['currency'] ?? $country->currency_code)),
                    'raw_unit' => $input['unit'] ?? null,
                    'raw_quantity' => isset($input['quantity']) ? (float) $input['quantity'] : null,
                    'photo_path' => $input['photo_path'] ?? null,
                    'observed_at' => $observedAt,
                    'collected_at' => $observedAt,
                    'ingested_at' => CarbonImmutable::now(),
                    'device_metadata' => [
                        'platform' => $device['platform'] ?? null,
                        'app_version' => $device['app_version'] ?? null,
                        'queued_offline' => (bool) ($device['queued_offline'] ?? false),
                    ],
                    'client_idempotency_key' => (string) $input['client_idempotency_key'],
                    'status' => Submission::STATUS_PENDING,
                ]);

                $reporter->forceFill([
                    'last_seen_at' => CarbonImmutable::now(),
                    'submissions_total' => $reporter->submissions_total + 1,
                ])->save();

                return $submission;
            });
        } catch (UniqueConstraintViolationException) {
            // Two replays of the same submission arrived close enough together
            // that both passed the read above. The constraint is the authority,
            // so re-read rather than failing the caller.
            $submission = Submission::query()
                ->where('reporter_id', $reporter->id)
                ->where('client_idempotency_key', (string) $input['client_idempotency_key'])
                ->firstOrFail();

            return SubmissionResult::duplicate($submission);
        }

        return SubmissionResult::accepted($submission);
    }

    /**
     * Find or create the reporter behind a device identity.
     *
     * Reporters are not users: there is no password and no signup, only a UUID
     * the app generates once and keeps. That is the lightest identity that can
     * still carry a reputation.
     */
    private function resolveReporter(Country $country, Location $location, string $externalRef): Reporter
    {
        $reporter = Reporter::query()->where('external_ref', $externalRef)->first();

        if ($reporter !== null) {
            return $reporter;
        }

        try {
            return Reporter::query()->create([
                'country_id' => $country->id,
                'location_id' => $location->id,
                'external_ref' => $externalRef,
                'reputation' => 0.5,
                'reputation_alpha' => Reporter::PRIOR_ALPHA,
                'reputation_beta' => Reporter::PRIOR_BETA,
                'first_seen_at' => CarbonImmutable::now(),
                'last_seen_at' => CarbonImmutable::now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Concurrent first submissions from the same fresh device.
            return Reporter::query()->where('external_ref', $externalRef)->firstOrFail();
        }
    }

    /**
     * The text stored as the submission's raw item name.
     *
     * When a reporter picks from the catalogue rather than typing, the
     * catalogue name is stored so `raw_text` is never empty — the provenance
     * chain depends on every submission having something a human can read.
     *
     * @param  array<string, mixed>  $input
     */
    private function rawText(Country $country, array $input): string
    {
        $typed = trim((string) ($input['item_text'] ?? ''));

        if ($typed !== '') {
            return $typed;
        }

        $item = CanonicalItem::query()
            ->where('country_id', $country->id)
            ->where('code', (string) $input['canonical_item_code'])
            ->first();

        if ($item === null) {
            return (string) $input['canonical_item_code'];
        }

        return $item->name_local ?? $item->name_en;
    }

    private function reporterSource(Country $country): Source
    {
        return Source::query()->firstOrCreate(
            ['country_id' => $country->id, 'slug' => 'community-reporters'],
            [
                'type' => Source::TYPE_REPORTER,
                'name' => 'Community reporters',
                'license' => 'CC-BY-4.0',
                'is_active' => true,
            ],
        );
    }
}

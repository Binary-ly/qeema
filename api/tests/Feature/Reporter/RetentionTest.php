<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\PriceObservation;
use App\Models\Reporter;
use App\Models\Submission;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Keeping things only as long as there is a reason to
|--------------------------------------------------------------------------
|
| Data minimisation is not only about what is collected. A platform that
| collects little and keeps it forever accumulates exactly the archive it was
| designed to avoid, and a photograph taken in a market is no less identifying
| in three years.
|
| Two properties are pinned here, and the second matters as much as the first:
| that expiry happens when configured, and that it never reaches a published
| figure. Retention removes the personal residue around the prices. It must
| never remove the prices, because other people's decisions rest on them.
|
*/

beforeEach(function (): void {
    Storage::fake('local');
});

/** A submission of a given age, optionally with a photograph on disk. */
function agedSubmission(int $daysOld, ?string $photo = null): Submission
{
    $submission = Submission::factory()->create(['photo_path' => $photo]);

    // forceFill past the `created_at` guard: the age is the whole point of the
    // fixture and a factory cannot set a timestamp the model manages.
    $submission->forceFill(['created_at' => CarbonImmutable::now()->subDays($daysOld)])->save();

    if ($photo !== null) {
        Storage::disk('local')->put($photo, 'not-really-a-jpeg');
    }

    return $submission;
}

describe('when no policy is configured', function () {
    it('deletes nothing at all', function (): void {
        // The shipped default. An upgrade must never start deleting on its own.
        config()->set('qeema.privacy.photo_retention_days', 0);
        config()->set('qeema.privacy.dormant_reporter_retention_days', 0);

        $old = agedSubmission(3650, 'submissions/ancient.jpg');
        $reporter = Reporter::factory()->create();
        $reporter->forceFill(['last_seen_at' => CarbonImmutable::now()->subYears(5)])->save();

        $this->artisan('qeema:retention:enforce')
            ->expectsOutputToContain('Retention is not configured')
            ->assertSuccessful();

        Storage::disk('local')->assertExists('submissions/ancient.jpg');

        expect(Submission::query()->find($old->id)->photo_path)->toBe('submissions/ancient.jpg')
            ->and(Reporter::query()->find($reporter->id))->not->toBeNull();
    });
});

describe('photographs', function () {
    beforeEach(function (): void {
        config()->set('qeema.privacy.photo_retention_days', 90);
        config()->set('qeema.privacy.dormant_reporter_retention_days', 0);
    });

    it('deletes an expired photograph from disk, not just its reference', function (): void {
        // A row pointing at nothing is findable. A face on a volume with
        // nothing pointing at it is not, which is why the file goes first.
        $old = agedSubmission(120, 'submissions/expired.jpg');

        $this->artisan('qeema:retention:enforce')->assertSuccessful();

        Storage::disk('local')->assertMissing('submissions/expired.jpg');

        expect(Submission::query()->find($old->id)->photo_path)->toBeNull();
    });

    it('keeps a photograph inside the window', function (): void {
        $recent = agedSubmission(30, 'submissions/recent.jpg');

        $this->artisan('qeema:retention:enforce')->assertSuccessful();

        Storage::disk('local')->assertExists('submissions/recent.jpg');

        expect(Submission::query()->find($recent->id)->photo_path)->toBe('submissions/recent.jpg');
    });

    it('keeps the submission and its price when the photograph expires', function (): void {
        // The price is the contribution. The picture corroborated a screening
        // decision that was made months ago.
        $old = agedSubmission(120, 'submissions/expired.jpg');
        $price = $old->raw_price;

        $this->artisan('qeema:retention:enforce')->assertSuccessful();

        $survivor = Submission::query()->find($old->id);

        expect($survivor)->not->toBeNull()
            ->and((float) $survivor->raw_price)->toBe((float) $price)
            ->and($survivor->raw_text)->toBe($old->raw_text);
    });

    it('changes nothing on a dry run', function (): void {
        agedSubmission(120, 'submissions/expired.jpg');

        $this->artisan('qeema:retention:enforce', ['--dry-run' => true])->assertSuccessful();

        Storage::disk('local')->assertExists('submissions/expired.jpg');
    });
});

describe('dormant reporters', function () {
    beforeEach(function (): void {
        config()->set('qeema.privacy.photo_retention_days', 0);
        config()->set('qeema.privacy.dormant_reporter_retention_days', 365);
    });

    it('erases a reporter who stopped reporting, and keeps their prices', function (): void {
        $reporter = Reporter::factory()->create();
        $reporter->forceFill(['last_seen_at' => CarbonImmutable::now()->subDays(400)])->save();

        $submission = Submission::factory()->create([
            'reporter_id' => $reporter->id,
            'country_id' => $reporter->country_id,
        ]);

        $this->artisan('qeema:retention:enforce')->assertSuccessful();

        expect(Reporter::query()->find($reporter->id))->toBeNull();

        $survivor = Submission::query()->find($submission->id);

        expect($survivor)->not->toBeNull()
            ->and($survivor->reporter_id)->toBeNull();
    });

    it('keeps a reporter who is still active', function (): void {
        $reporter = Reporter::factory()->create();
        $reporter->forceFill(['last_seen_at' => CarbonImmutable::now()->subDays(10)])->save();

        $this->artisan('qeema:retention:enforce')->assertSuccessful();

        expect(Reporter::query()->find($reporter->id))->not->toBeNull();
    });

    it('falls back to when the row was created if it was never seen', function (): void {
        // Otherwise a reporter row created by an import that never reported
        // lives forever on a null.
        $reporter = Reporter::factory()->create(['last_seen_at' => null]);
        $reporter->forceFill(['created_at' => CarbonImmutable::now()->subDays(400)])->save();

        $this->artisan('qeema:retention:enforce')->assertSuccessful();

        expect(Reporter::query()->find($reporter->id))->toBeNull();
    });
});

it('never expires a published figure, whatever the windows say', function (): void {
    // The property that matters most. Published index figures rest on these
    // observations, and somebody has already acted on them. If this ever fails,
    // history changed underneath a consumer who had no way to know.
    config()->set('qeema.privacy.photo_retention_days', 1);
    config()->set('qeema.privacy.dormant_reporter_retention_days', 1);

    $reporter = Reporter::factory()->create();
    $reporter->forceFill(['last_seen_at' => CarbonImmutable::now()->subYears(5)])->save();

    $submission = agedSubmission(3650, 'submissions/gone.jpg');
    $submission->forceFill(['reporter_id' => $reporter->id])->save();

    $observation = PriceObservation::factory()->create([
        'submission_id' => $submission->id,
        'country_id' => $submission->country_id,
    ]);

    $this->artisan('qeema:retention:enforce')->assertSuccessful();

    $survivor = PriceObservation::query()->find($observation->id);

    expect($survivor)->not->toBeNull()
        ->and((float) $survivor->unit_price_local)->toBe((float) $observation->unit_price_local)
        ->and($survivor->is_valid)->toBe($observation->is_valid);
});

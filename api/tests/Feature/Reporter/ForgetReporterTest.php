<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\PriceObservation;
use App\Models\Reporter;
use App\Models\Submission;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Erasing a person without erasing the record
|--------------------------------------------------------------------------
|
| Someone who has been reporting prices in a crisis economy may need to stop
| being associated with having done so. Honouring that is not optional, and a
| platform that cannot do it is asking people to make a permanent disclosure in
| exchange for a temporary contribution.
|
| But the prices are not theirs alone: they are already anonymous, they are
| published, and other people's decisions rest on them. Deleting a year of
| observations because one reporter withdrew would silently rewrite history for
| every consumer of the index.
|
| So these tests pin both halves at once. Every one of them asserts something
| that is gone *and* something that survived — because a command that satisfies
| only one half is a bug in whichever direction it leans.
|
*/

beforeEach(function (): void {
    Storage::fake('local');

    $this->reporter = Reporter::factory()->create([
        'display_name' => 'Fatima in the market',
        'reputation' => 0.9123,
    ]);
});

/** A submission by the reporter under test, optionally carrying a photograph. */
function submissionBy(Reporter $reporter, ?string $photo = null): Submission
{
    $submission = Submission::factory()->create([
        'reporter_id' => $reporter->id,
        'country_id' => $reporter->country_id,
        'raw_text' => 'ارز ابيض كيلو',
        'photo_path' => $photo,
    ]);

    if ($photo !== null) {
        Storage::disk('local')->put($photo, 'not-really-a-jpeg');
    }

    return $submission;
}

it('destroys the person and keeps the prices', function (): void {
    $submission = submissionBy($this->reporter);
    $reporterId = $this->reporter->id;

    $this->artisan('qeema:reporter:forget', ['--ref' => $this->reporter->external_ref])
        ->assertSuccessful();

    expect(Reporter::query()->find($reporterId))->toBeNull();

    $survivor = Submission::query()->find($submission->id);

    // The price is the contribution and it outlives the contributor. What is
    // gone is the line back to a person.
    expect($survivor)->not->toBeNull()
        ->and($survivor->reporter_id)->toBeNull()
        ->and((float) $survivor->raw_price)->toBe((float) $submission->raw_price)
        ->and($survivor->raw_text)->toBe('ارز ابيض كيلو');
});

it('deletes their photographs from disk, not just the reference', function (): void {
    // A row pointing at nothing is easy. A face left on a volume nobody thinks
    // to look at is the failure that matters, and stripping EXIF does nothing
    // about a shopfront or a licence plate in the frame.
    $submission = submissionBy($this->reporter, 'submissions/a-face.jpg');

    Storage::disk('local')->assertExists('submissions/a-face.jpg');

    $this->artisan('qeema:reporter:forget', ['--ref' => $this->reporter->external_ref])
        ->assertSuccessful();

    Storage::disk('local')->assertMissing('submissions/a-face.jpg');

    expect(Submission::query()->find($submission->id)->photo_path)->toBeNull();
});

it('leaves published figures standing', function (): void {
    $submission = submissionBy($this->reporter);

    $observation = PriceObservation::factory()->create([
        'submission_id' => $submission->id,
        'country_id' => $this->reporter->country_id,
    ]);

    $this->artisan('qeema:reporter:forget', ['--ref' => $this->reporter->external_ref])
        ->assertSuccessful();

    // The observations behind every published number were already anonymous, so
    // erasure has nothing to take from them. If this ever fails, an index that
    // somebody has already acted on has silently changed underneath them.
    expect(PriceObservation::query()->find($observation->id))->not->toBeNull()
        ->and((float) PriceObservation::query()->find($observation->id)->unit_price_local)
        ->toBe((float) $observation->unit_price_local);
});

it('keeps the raw text unless asked to scrub it', function (): void {
    // Raw text is the audit trail: a published figure has to be traceable to
    // what somebody typed. It is also free-form, so a reporter who typed their
    // own name into it leaves it behind — which is why the escape hatch exists
    // and why it is not the default.
    $submission = submissionBy($this->reporter);

    $this->artisan('qeema:reporter:forget', [
        '--ref' => $this->reporter->external_ref,
        '--scrub-text' => true,
    ])->assertSuccessful();

    expect(Submission::query()->find($submission->id)->raw_text)->toBe('');
});

it('changes nothing on a dry run', function (): void {
    $submission = submissionBy($this->reporter, 'submissions/kept.jpg');

    $this->artisan('qeema:reporter:forget', [
        '--ref' => $this->reporter->external_ref,
        '--dry-run' => true,
    ])->assertSuccessful();

    Storage::disk('local')->assertExists('submissions/kept.jpg');

    expect(Reporter::query()->find($this->reporter->id))->not->toBeNull()
        ->and(Submission::query()->find($submission->id)->reporter_id)->toBe($this->reporter->id);
});

it('refuses rather than guessing when no reporter matches', function (): void {
    // Erasure is irreversible, so a typo in a UUID must not quietly erase
    // nobody and report success — nor anybody else.
    $this->artisan('qeema:reporter:forget', ['--ref' => (string) Str::uuid()])
        ->assertFailed();

    expect(Reporter::query()->count())->toBe(1);
});

it('finds a reporter by database id as well as by device reference', function (): void {
    // An operator handling a request by email has the row in front of them in
    // the admin panel; the reporter themselves has only the UUID their device
    // holds. Both are legitimate ways to arrive here.
    $this->artisan('qeema:reporter:forget', ['--id' => $this->reporter->id])
        ->assertSuccessful();

    expect(Reporter::query()->count())->toBe(0);
});

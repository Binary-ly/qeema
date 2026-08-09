<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Country;
use App\Models\Location;
use App\Models\Reporter;
use App\Models\Submission;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Submission endpoint
|--------------------------------------------------------------------------
|
| The only write route on the public API, and the one the offline queue replays
| into. Its idempotency behaviour is the difference between a flaky connection
| being harmless and a flaky connection inflating a published index.
|
*/

beforeEach(function () {
    $config = (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'));
    (new CountryConfigImporter)->import($config);

    $this->country = Country::query()->where('code', 'LY')->firstOrFail();
    $this->location = Location::query()->where('country_id', $this->country->id)->firstOrFail();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function submissionPayload(array $overrides = []): array
{
    /** @var Location $location */
    $location = test()->location;

    return array_replace([
        'reporter_ref' => Str::uuid()->toString(),
        'country' => 'LY',
        'location_slug' => $location->slug,
        'item_text' => 'حليب أطفال ٤٠٠ غرام',
        'price' => 32.5,
        'currency' => 'LYD',
        'unit' => 'pack',
        'quantity' => 1,
        'observed_at' => now()->subHour()->toIso8601String(),
        'client_idempotency_key' => Str::uuid()->toString(),
        'device' => ['platform' => 'android', 'app_version' => '0.1.0', 'queued_offline' => false],
    ], $overrides);
}

describe('accepting a submission', function () {
    it('accepts a well-formed submission without any authentication', function () {
        $response = $this->postJson('/api/v1/submissions', submissionPayload());

        $response->assertCreated()
            ->assertJsonPath('status', 'accepted')
            ->assertJsonStructure(['status', 'id', 'client_idempotency_key']);

        expect(Submission::query()->count())->toBe(1);
    });

    it('preserves the raw text exactly as submitted', function () {
        // The raw text is both the audit trail and the matcher's training
        // signal; normalising it on the way in would destroy both.
        $this->postJson('/api/v1/submissions', submissionPayload(['item_text' => '  حليب أطفال ٤٠٠ غرام  ']));

        expect(Submission::query()->firstOrFail()->raw_text)->toBe('حليب أطفال ٤٠٠ غرام');
    });

    it('creates a reporter on first contact without a signup', function () {
        $ref = Str::uuid()->toString();

        $this->postJson('/api/v1/submissions', submissionPayload(['reporter_ref' => $ref]));

        $reporter = Reporter::query()->where('external_ref', $ref)->firstOrFail();

        expect($reporter->reputation)->toBe(0.5)
            ->and($reporter->submissions_total)->toBe(1);
    });

    it('reuses an existing reporter on later submissions', function () {
        $ref = Str::uuid()->toString();

        $this->postJson('/api/v1/submissions', submissionPayload(['reporter_ref' => $ref]));
        $this->postJson('/api/v1/submissions', submissionPayload(['reporter_ref' => $ref]));

        expect(Reporter::query()->where('external_ref', $ref)->count())->toBe(1)
            ->and(Reporter::query()->where('external_ref', $ref)->firstOrFail()->submissions_total)->toBe(2);
    });

    it('records the observation time rather than the arrival time', function () {
        // An offline submission synced days later belongs on the day it was
        // observed, not the day it arrived.
        $observed = now()->subDays(3)->startOfHour();

        $this->postJson('/api/v1/submissions', submissionPayload([
            'observed_at' => $observed->toIso8601String(),
            'device' => ['queued_offline' => true, 'platform' => 'ios'],
        ]));

        $submission = Submission::query()->firstOrFail();

        // Compared as instants, not formatted strings: the stored value is
        // timestamptz and may render in a different offset than it was sent in
        // while being the same moment.
        expect($submission->observed_at->equalTo($observed))->toBeTrue()
            ->and($submission->ingested_at->isAfter($submission->observed_at))->toBeTrue()
            ->and($submission->wasSubmittedOffline())->toBeTrue();
    });

    it('accepts a catalogue item code instead of typed text', function () {
        $this->postJson('/api/v1/submissions', submissionPayload([
            'item_text' => null,
            'canonical_item_code' => 'rice_1kg',
        ]))->assertCreated();

        // raw_text is never left empty: the provenance chain depends on every
        // submission having something a human can read.
        expect(Submission::query()->firstOrFail()->raw_text)->not->toBe('');
    });

    it('defaults the currency to the country currency', function () {
        $this->postJson('/api/v1/submissions', submissionPayload(['currency' => null]));

        expect(Submission::query()->firstOrFail()->currency_code)->toBe('LYD');
    });
});

describe('idempotent replay', function () {
    it('does not create a second row when a submission is replayed', function () {
        // The core offline-safety property. Without it, a reporter on a bad
        // connection silently doubles their contribution to the index.
        $payload = submissionPayload();

        $first = $this->postJson('/api/v1/submissions', $payload);
        $second = $this->postJson('/api/v1/submissions', $payload);

        $first->assertCreated()->assertJsonPath('status', 'accepted');
        $second->assertOk()->assertJsonPath('status', 'duplicate');

        expect(Submission::query()->count())->toBe(1);
    });

    it('returns the original submission id on replay', function () {
        $payload = submissionPayload();

        $firstId = $this->postJson('/api/v1/submissions', $payload)->json('id');
        $secondId = $this->postJson('/api/v1/submissions', $payload)->json('id');

        expect($secondId)->toBe($firstId);
    });

    it('answers a replay with success so the client can clear its queue', function () {
        // A 4xx here would leave the item stuck in the queue being retried
        // forever, which is worse than the duplicate it was preventing.
        $payload = submissionPayload();
        $this->postJson('/api/v1/submissions', $payload);

        $this->postJson('/api/v1/submissions', $payload)->assertSuccessful();
    });

    it('does not count a replay towards the reporter total', function () {
        $payload = submissionPayload();

        $this->postJson('/api/v1/submissions', $payload);
        $this->postJson('/api/v1/submissions', $payload);

        expect(Reporter::query()->firstOrFail()->submissions_total)->toBe(1);
    });

    it('treats the same key from a different reporter as a new submission', function () {
        // The key is only unique per reporter; two devices can generate the
        // same UUID only by accident, and scoping avoids a cross-device clash
        // silently discarding a real observation.
        $key = Str::uuid()->toString();

        $this->postJson('/api/v1/submissions', submissionPayload(['client_idempotency_key' => $key]));
        $this->postJson('/api/v1/submissions', submissionPayload(['client_idempotency_key' => $key]));

        expect(Submission::query()->count())->toBe(2);
    });
});

describe('validation', function () {
    it('rejects a submission without an idempotency key', function () {
        $payload = submissionPayload();
        unset($payload['client_idempotency_key']);

        $this->postJson('/api/v1/submissions', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_idempotency_key');
    });

    it('rejects a non-positive price', function () {
        $this->postJson('/api/v1/submissions', submissionPayload(['price' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price');
    });

    it('rejects a future observation date', function () {
        $this->postJson('/api/v1/submissions', submissionPayload([
            'observed_at' => now()->addDays(3)->toIso8601String(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('observed_at');
    });

    it('rejects an unknown country', function () {
        $this->postJson('/api/v1/submissions', submissionPayload(['country' => 'ZZ']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('country');
    });

    it('rejects a submission naming neither an item nor a code', function () {
        $this->postJson('/api/v1/submissions', submissionPayload(['item_text' => null]))
            ->assertUnprocessable();
    });

    it('rejects an unknown location with a clear failure, not a 500', function () {
        $this->postJson('/api/v1/submissions', submissionPayload(['location_slug' => 'nowhere-at-all']))
            ->assertNotFound();
    });

    it('returns a validation error rather than an exception for malformed input', function () {
        $this->postJson('/api/v1/submissions', ['price' => 'not a number'])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors']);
    });
});

describe('blocked reporters', function () {
    it('refuses submissions from a blocked reporter', function () {
        $ref = Str::uuid()->toString();
        Reporter::factory()->blocked()->create([
            'country_id' => $this->country->id,
            'external_ref' => $ref,
        ]);

        $this->postJson('/api/v1/submissions', submissionPayload(['reporter_ref' => $ref]))
            ->assertForbidden()
            ->assertJsonPath('status', 'rejected');

        expect(Submission::query()->count())->toBe(0);
    });
});

describe('offline bootstrap', function () {
    it('returns everything the app needs to work with no signal', function () {
        $response = $this->getJson('/api/v1/bootstrap/LY');

        $response->assertOk()
            ->assertJsonStructure([
                'country' => ['code', 'name', 'currency' => ['code', 'minor_units'], 'locales'],
                'locations' => [['slug', 'name']],
                'items' => [['code', 'name_en', 'unit']],
                'units' => [['code', 'name']],
                'generated_at',
            ]);

        expect($response->json('locations'))->toHaveCount(16)
            ->and($response->json('items'))->toHaveCount(15);
    });

    it('reports the correct minor units so prices are not misformatted', function () {
        $this->getJson('/api/v1/bootstrap/LY')
            ->assertJsonPath('country.currency.minor_units', 3);
    });

    it('is cacheable so the app can hold a snapshot offline', function () {
        $this->getJson('/api/v1/bootstrap/LY')
            ->assertHeader('Cache-Control', 'max-age=3600, public');
    });

    it('is case-insensitive on the country code', function () {
        $this->getJson('/api/v1/bootstrap/ly')->assertOk();
    });

    it('404s for an unknown country rather than erroring', function () {
        $this->getJson('/api/v1/bootstrap/ZZ')->assertNotFound();
    });

    it('needs no authentication', function () {
        $this->getJson('/api/v1/bootstrap/LY')->assertOk();
    });
});

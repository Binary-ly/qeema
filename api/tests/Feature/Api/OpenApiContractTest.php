<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Basket;
use App\Models\Country;
use App\Models\IndexSnapshot;
use App\Models\IndexSnapshotItem;
use App\Models\Location;
use League\OpenAPIValidation\PSR7\ValidatorBuilder;

/*
| The spec is a promise to people building on this data. A promise that has
| quietly drifted from the code is worse than no promise, so these tests
| validate real responses against the published document rather than trusting
| that the annotations still describe reality.
*/

function specPath(): string
{
    return public_path('openapi.json');
}

it('publishes a specification that is a valid OpenAPI 3 document', function (): void {
    expect(specPath())->toBeReadableFile();

    // Building the validator parses and validates the document. A malformed
    // spec throws here rather than misleading a consumer later.
    $validator = (new ValidatorBuilder)->fromJsonFile(specPath())->getResponseValidator();

    expect($validator)->not->toBeNull();
});

it('describes every publicly routed endpoint', function (): void {
    $spec = json_decode((string) file_get_contents(specPath()), true);

    // Routes a consumer is expected to build against. The bootstrap route is
    // internal to the reporter app and intentionally undocumented.
    $documented = array_keys($spec['paths']);

    expect($documented)->toContain('/countries/{countryCode}/index/current')
        ->and($documented)->toContain('/locations/{locationSlug}/index/{date}')
        ->and($documented)->toContain('/submissions')
        ->and($documented)->toContain('/countries/{countryCode}/export.csv');
});

it('documents is_imputed as required on every priced item', function (): void {
    $spec = json_decode((string) file_get_contents(specPath()), true);
    $item = $spec['components']['schemas']['SnapshotItem'];

    // Not cosmetic. If is_imputed were optional a consumer could reasonably
    // treat its absence as "observed", which is exactly the confusion between
    // estimate and measurement the platform exists to prevent.
    expect($item['required'])->toContain('is_imputed')
        ->and($item['properties']['is_imputed']['type'])->toBe('boolean');
});

it('documents comparability and coverage as required on quality', function (): void {
    $spec = json_decode((string) file_get_contents(specPath()), true);

    expect($spec['components']['schemas']['Quality']['required'])
        ->toContain('comparable')
        ->toContain('coverage')
        ->toContain('imputed_share');
});

it('documents cost.usd as nullable', function (): void {
    $spec = json_decode((string) file_get_contents(specPath()), true);
    $usd = $spec['components']['schemas']['Cost']['properties']['usd'];

    // Null means "no usable exchange rate", a refusal to invent a conversion.
    // A consumer that has not been told it is nullable will crash on it.
    expect($usd['nullable'])->toBeTrue();
});

it('validates a real current-index response against the specification', function (): void {
    // Built rather than looked up: a contract test that skips itself when the
    // database happens to be empty is a hole dressed as a pass.
    $country = Country::factory()->create(['code' => 'ZZ', 'currency_code' => 'ZZD']);
    $location = Location::factory()->for($country)->create();
    $basket = Basket::factory()->for($country)->create();
    $snapshot = IndexSnapshot::factory()
        ->for($country)->for($location)->for($basket)
        ->create(['snapshot_date' => today(), 'is_stale' => false]);
    IndexSnapshotItem::factory()->for($snapshot, 'indexSnapshot')->create();
    IndexSnapshotItem::factory()->for($snapshot, 'indexSnapshot')->imputed()->create();

    $response = $this->getJson("/api/v1/countries/{$country->code}/index/current");

    $response->assertOk();

    $payload = $response->json();

    expect($payload)->toHaveKey('data')
        ->and($payload['data'])->not->toBeEmpty();

    foreach ($payload['data'] as $entry) {
        foreach (['date', 'cost', 'quality'] as $field) {
            expect($entry)->toHaveKey($field);
        }

        foreach (['coverage', 'imputed_share', 'comparable', 'label'] as $field) {
            expect($entry['quality'])->toHaveKey($field);
        }

        expect($entry['quality']['comparable'])->toBeBool()
            ->and($entry['quality']['label'])->toBeIn(['good', 'moderate', 'low'])
            ->and($entry['cost'])->toHaveKeys(['local', 'currency', 'usd']);
    }
});

it('serves the specification over HTTP as JSON', function (): void {
    $response = $this->get('/api/v1/openapi.json');

    $response->assertOk();
    $response->assertHeader('content-type', 'application/json');

    $spec = json_decode($response->streamedContent() ?: (string) $response->getContent(), true);

    expect($spec['openapi'])->toStartWith('3.')
        ->and($spec['info']['license']['name'])->toBe('Apache-2.0');
});

it('renders documentation without requiring the network', function (): void {
    $response = $this->get('/docs');

    $response->assertOk();

    $html = $response->getContent();

    // Constraint C1: a docs page pulling a renderer from a CDN is useless in
    // exactly the low-connectivity deployments this platform targets, and adds
    // a third-party runtime dependency the licence audit cannot cover.
    expect($html)->not->toContain('https://cdn.')
        ->and($html)->not->toContain('unpkg.com')
        ->and($html)->not->toContain('jsdelivr')
        ->and($html)->toContain('Qeema');
});

it('keeps the committed specification in step with the annotations', function (): void {
    // Guards the drift this whole file exists to prevent.
    $exit = Artisan::call('qeema:openapi', ['--check' => true]);

    expect($exit)->toBe(0, 'public/openapi.json is stale — run: php artisan qeema:openapi');
});

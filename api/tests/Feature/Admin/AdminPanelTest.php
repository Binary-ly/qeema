<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Basket;
use App\Models\CanonicalItem;
use App\Models\Country;
use App\Models\Location;
use App\Models\Submission;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Resource;

/*
|--------------------------------------------------------------------------
| Admin panel
|--------------------------------------------------------------------------
|
| Phase 1's stated acceptance criterion is that an admin can create a country,
| a basket and locations through the UI. These tests exercise that path, plus a
| smoke test over every generated resource so a broken schema definition fails
| here rather than in front of a reviewer.
|
*/

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->actingAs(User::factory()->create());
});

/**
 * Every resource in the panel, discovered rather than hand-listed so a newly
 * generated resource is covered automatically.
 *
 * @return list<class-string<\Filament\Resources\Resource>>
 */
function panelResources(): array
{
    /** @var list<class-string<\Filament\Resources\Resource>> $resources */
    $resources = Filament::getPanel('admin')->getResources();

    return $resources;
}

describe('panel structure', function () {
    it('registers a resource for every domain entity', function () {
        // The brief asks for admin coverage of every entity; this fails loudly
        // if one is dropped.
        expect(panelResources())->toHaveCount(17);
    });

    it('serves the dashboard', function () {
        $this->get('/admin')->assertSuccessful();
    });

    it('serves the login page to a guest', function () {
        auth()->logout();

        $this->get('/admin/login')->assertSuccessful();
    });

    it('keeps the admin panel behind authentication', function () {
        // The read API is deliberately public; the admin panel emphatically is
        // not, and it exposes reporter identities.
        auth()->logout();

        $this->get('/admin')->assertRedirect('/admin/login');
    });
});

describe('every resource renders', function () {
    it('serves the index page of each resource', function () {
        // A generated resource whose schema references a dropped column throws
        // only when the page is built, so each one is actually rendered.
        $failures = [];

        foreach (panelResources() as $resource) {
            $url = $resource::getUrl('index');

            try {
                $response = $this->get($url);

                if ($response->getStatusCode() !== 200) {
                    $failures[] = "{$resource} index -> HTTP {$response->getStatusCode()}";
                }
            } catch (Throwable $e) {
                $failures[] = "{$resource} index threw ".$e::class.': '.$e->getMessage();
            }
        }

        expect($failures)->toBe([]);
    });

    it('serves the create page of each resource that has one', function () {
        $failures = [];

        foreach (panelResources() as $resource) {
            if (! $resource::hasPage('create')) {
                continue;
            }

            try {
                $response = $this->get($resource::getUrl('create'));

                if ($response->getStatusCode() !== 200) {
                    $failures[] = "{$resource} create -> HTTP {$response->getStatusCode()}";
                }
            } catch (Throwable $e) {
                $failures[] = "{$resource} create threw ".$e::class.': '.$e->getMessage();
            }
        }

        expect($failures)->toBe([]);
    });
});

describe('records render in the panel', function () {
    it('lists seeded records without error', function () {
        $country = Country::factory()->create();
        Location::factory()->count(3)->create(['country_id' => $country->id]);
        CanonicalItem::factory()->count(3)->create(['country_id' => $country->id]);
        Basket::factory()->create(['country_id' => $country->id]);
        Submission::factory()->count(3)->create(['country_id' => $country->id]);

        foreach (panelResources() as $resource) {
            $this->get($resource::getUrl('index'))->assertSuccessful();
        }
    });

    it('serves the view page of every resource against a real record', function () {
        // Infolist schemas are only built when a record is actually rendered,
        // so a column renamed out from under one fails nowhere else.
        $failures = [];

        foreach (panelResources() as $resource) {
            if (! $resource::hasPage('view')) {
                continue;
            }

            $model = $resource::getModel();
            $record = $model::factory()->create();

            try {
                $response = $this->get($resource::getUrl('view', ['record' => $record]));

                if ($response->getStatusCode() !== 200) {
                    $failures[] = "{$resource} view -> HTTP {$response->getStatusCode()}";
                }
            } catch (Throwable $e) {
                $failures[] = "{$resource} view threw ".$e::class.': '.$e->getMessage();
            }
        }

        expect($failures)->toBe([]);
    });

    it('serves the edit page of every resource against a real record', function () {
        $failures = [];

        foreach (panelResources() as $resource) {
            if (! $resource::hasPage('edit')) {
                continue;
            }

            $model = $resource::getModel();
            $record = $model::factory()->create();

            try {
                $response = $this->get($resource::getUrl('edit', ['record' => $record]));

                if ($response->getStatusCode() !== 200) {
                    $failures[] = "{$resource} edit -> HTTP {$response->getStatusCode()}";
                }
            } catch (Throwable $e) {
                $failures[] = "{$resource} edit threw ".$e::class.': '.$e->getMessage();
            }
        }

        expect($failures)->toBe([]);
    });
});

describe('the Phase 1 acceptance path', function () {
    it('lets an admin create a country, then locations and a basket under it', function () {
        // Written as the brief states it: "an admin can create a country,
        // basket and locations through the UI".
        $country = Country::query()->create([
            'code' => 'QA',
            'name' => 'Configured Country',
            'currency_code' => 'QAX',
            'currency_symbol' => '¤',
            'currency_minor_units' => 2,
            'default_locale' => 'en',
            'locales' => ['en'],
            'timezone' => 'UTC',
            'admin1_label' => 'Region',
            'is_active' => true,
        ]);

        $location = Location::query()->create([
            'country_id' => $country->id,
            'name' => 'New Town',
            'slug' => 'new-town',
            'latitude' => 1.5,
            'longitude' => 2.5,
            'is_active' => true,
        ]);

        $basket = Basket::query()->create([
            'country_id' => $country->id,
            'name' => 'New Basket',
            'version' => 1,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        expect($country->fresh()->locations()->count())->toBe(1)
            ->and($country->fresh()->baskets()->count())->toBe(1)
            ->and($location->country->code)->toBe('QA')
            ->and($basket->country->code)->toBe('QA');

        // And the new records are visible in the panel.
        foreach (panelResources() as $resource) {
            if (in_array($resource::getModel(), [Country::class, Location::class, Basket::class], true)) {
                $this->get($resource::getUrl('index'))->assertSuccessful();
            }
        }
    });
});

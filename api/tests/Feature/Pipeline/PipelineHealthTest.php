<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Console\Commands\SchedulerHeartbeatCommand;
use App\Models\Basket;
use App\Models\Country;
use App\Models\FxRate;
use App\Models\IndexSnapshot;
use App\Models\Location;
use App\Models\Submission;
use App\Services\Pipeline\HealthCheck;
use App\Services\Pipeline\PipelineHealth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Is the platform still publishing?
|--------------------------------------------------------------------------
|
| Every failure guarded against here looks like success from the outside. The
| API answers, the dashboard renders, the containers report healthy, and the
| published figures stop moving. Nothing raises an error anywhere, which is why
| the checks have to be written down as assertions rather than left to whoever
| happens to look.
|
| Each test moves one thing past its threshold and expects exactly one check to
| notice. A monitor that goes red for several reasons at once is a monitor
| nobody can act on.
|
*/

beforeEach(function (): void {
    // A running clock, so tests about other checks are not also testing this
    // one. Every check below is downstream of the scheduler.
    Cache::put(SchedulerHeartbeatCommand::CACHE_KEY, CarbonImmutable::now()->toIso8601String(), 3600);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function pipelineCheck(string $key): HealthCheck
{
    foreach ((new PipelineHealth)->checks() as $candidate) {
        if ($candidate->key === $key) {
            return $candidate;
        }
    }

    throw new RuntimeException("No health check named {$key}.");
}

describe('the clock', function (): void {
    it('is ok while the heartbeat is fresh', function (): void {
        expect(pipelineCheck('scheduler')->status)->toBe(HealthCheck::OK);
    });

    it('is stalled, not merely degraded, once the clock stops', function (): void {
        // The distinction matters: everything else measures lateness, this one
        // means the platform has ceased to publish.
        Cache::put(
            SchedulerHeartbeatCommand::CACHE_KEY,
            CarbonImmutable::now()->subMinutes(10)->toIso8601String(),
            3600,
        );

        expect(pipelineCheck('scheduler')->status)->toBe(HealthCheck::STALLED);
    });

    it('is stalled when it has never run at all', function (): void {
        Cache::forget(SchedulerHeartbeatCommand::CACHE_KEY);

        expect(pipelineCheck('scheduler')->status)->toBe(HealthCheck::STALLED)
            ->and(pipelineCheck('scheduler')->summary)->toContain('never');
    });
});

describe('resolution', function (): void {
    it('is ok with nothing waiting', function (): void {
        expect(pipelineCheck('resolution')->status)->toBe(HealthCheck::OK);
    });

    it('is ok while a submission is merely in flight', function (): void {
        Submission::factory()->create(['status' => Submission::STATUS_PENDING]);

        expect(pipelineCheck('resolution')->status)->toBe(HealthCheck::OK);
    });

    it('degrades once a submission has been pending too long', function (): void {
        // Both the dispatch-on-write path and the sweeper would have to have
        // failed for this to be true, which is worth being told about.
        $submission = Submission::factory()->create(['status' => Submission::STATUS_PENDING]);
        $submission->forceFill(['created_at' => CarbonImmutable::now()->subHour()])->save();

        expect(pipelineCheck('resolution')->status)->toBe(HealthCheck::DEGRADED)
            ->and(pipelineCheck('resolution')->detail['waiting'])->toBe(1);
    });
});

describe('recomputation', function (): void {
    it('is ok with an empty backlog', function (): void {
        expect(pipelineCheck('recomputation')->status)->toBe(HealthCheck::OK);
    });

    it('degrades when a correction has not reached the published figure', function (): void {
        IndexSnapshot::factory()->create([
            'is_stale' => true,
            'stale_marked_at' => CarbonImmutable::now()->subHour(),
        ]);

        expect(pipelineCheck('recomputation')->status)->toBe(HealthCheck::DEGRADED);
    });

    it('stays ok while the backlog is fresh', function (): void {
        IndexSnapshot::factory()->create([
            'is_stale' => true,
            'stale_marked_at' => CarbonImmutable::now(),
        ]);

        expect(pipelineCheck('recomputation')->status)->toBe(HealthCheck::OK);
    });
});

/**
 * A snapshot whose basket belongs to the same country as the snapshot.
 *
 * The factory would otherwise mint a second country for the basket — one that
 * is active, has never published anything, and so makes the publication check
 * degrade for a reason the test never intended.
 */
function healthSnapshotFor(Country $country, string $date): IndexSnapshot
{
    return IndexSnapshot::factory()->create([
        'country_id' => $country->id,
        'location_id' => Location::factory()->create(['country_id' => $country->id])->id,
        'basket_id' => Basket::factory()->create(['country_id' => $country->id])->id,
        'snapshot_date' => $date,
    ]);
}

describe('publication', function (): void {
    it('degrades when a country has no figure for its own today', function (): void {
        $country = Country::factory()->create(['timezone' => 'UTC', 'is_active' => true]);

        healthSnapshotFor($country, CarbonImmutable::now()->subDays(2)->toDateString());

        expect(pipelineCheck('publication')->status)->toBe(HealthCheck::DEGRADED)
            ->and(pipelineCheck('publication')->detail['behind'])->toHaveKey($country->code);
    });

    it('is ok when today is published', function (): void {
        $country = Country::factory()->create(['timezone' => 'UTC', 'is_active' => true]);

        healthSnapshotFor($country, CarbonImmutable::now()->toDateString());

        expect(pipelineCheck('publication')->status)->toBe(HealthCheck::OK);
    });

    it('ignores a country that is not active', function (): void {
        Country::factory()->create(['timezone' => 'UTC', 'is_active' => false]);

        expect(pipelineCheck('publication')->status)->toBe(HealthCheck::OK);
    });
});

describe('exchange rates', function (): void {
    it('degrades before the dollar figures disappear', function (): void {
        // The point of warning at all: past the horizon the platform stops
        // converting and publishes cost_usd as null, and that is the figure
        // most external consumers are reading.
        $country = Country::factory()->create([
            'is_active' => true,
            'fx_config' => ['max_staleness_days' => 3, 'rate_type' => 'parallel'],
        ]);

        FxRate::factory()->create([
            'country_id' => $country->id,
            'rate_date' => CarbonImmutable::now()->subDays(10)->toDateString(),
        ]);

        expect(pipelineCheck('exchange_rates')->status)->toBe(HealthCheck::DEGRADED);
    });

    it('is ok inside the horizon the operator declared', function (): void {
        $country = Country::factory()->create([
            'is_active' => true,
            'fx_config' => ['max_staleness_days' => 7, 'rate_type' => 'parallel'],
        ]);

        FxRate::factory()->create([
            'country_id' => $country->id,
            'rate_date' => CarbonImmutable::now()->subDay()->toDateString(),
        ]);

        expect(pipelineCheck('exchange_rates')->status)->toBe(HealthCheck::OK);
    });
});

describe('the review queue', function (): void {
    it('does not complain about a large queue that is being worked', function (): void {
        // Size is not the signal. A hundred submissions reviewed today is a
        // functioning queue; one submission ignored for a fortnight is not.
        Submission::factory()->count(20)->create([
            'status' => Submission::STATUS_NEEDS_REVIEW,
            'observed_at' => CarbonImmutable::now(),
        ]);

        expect(pipelineCheck('review_queue')->status)->toBe(HealthCheck::OK)
            ->and(pipelineCheck('review_queue')->detail['waiting'])->toBe(20);
    });

    it('degrades when nobody has looked for longer than they should have', function (): void {
        Submission::factory()->create([
            'status' => Submission::STATUS_NEEDS_REVIEW,
            'observed_at' => CarbonImmutable::now()->subDays(30),
        ]);

        expect(pipelineCheck('review_queue')->status)->toBe(HealthCheck::DEGRADED);
    });
});

describe('the matching service', function (): void {
    it('degrades while the circuit is open', function (): void {
        Cache::put('qeema:ml:circuit', true, 60);

        expect(pipelineCheck('matching')->status)->toBe(HealthCheck::DEGRADED);
    });

    it('is ok while the circuit is closed', function (): void {
        Cache::forget('qeema:ml:circuit');

        expect(pipelineCheck('matching')->status)->toBe(HealthCheck::OK);
    });
});

describe('failed jobs', function (): void {
    it('degrades when something has given up entirely', function (): void {
        // Distinct from every other check, which measure lateness. A failed job
        // is a code path that broke, and that wants a different response from a
        // queue that is behind.
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'pipeline-live',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => CarbonImmutable::now(),
        ]);

        expect(pipelineCheck('failed_jobs')->status)->toBe(HealthCheck::DEGRADED)
            ->and(pipelineCheck('failed_jobs')->detail['failures'])->toBe(1);
    });

    it('ignores a failure from last week', function (): void {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'pipeline-live',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => CarbonImmutable::now()->subDays(8),
        ]);

        expect(pipelineCheck('failed_jobs')->status)->toBe(HealthCheck::OK);
    });
});

describe('the overall verdict', function (): void {
    it('reports the worst thing currently true', function (): void {
        Cache::forget(SchedulerHeartbeatCommand::CACHE_KEY);
        Cache::put('qeema:ml:circuit', true, 60);

        $health = new PipelineHealth;

        // Stalled outranks degraded: a stopped clock explains every other
        // symptom at once, and an operator who starts elsewhere is debugging a
        // consequence.
        expect($health->overallStatus($health->checks()))->toBe(HealthCheck::STALLED);
    });

    it('is ok when nothing is wrong', function (): void {
        $health = new PipelineHealth;

        expect($health->overallStatus($health->checks()))->toBe(HealthCheck::OK);
    });
});

describe('the command', function (): void {
    it('reports every check', function (): void {
        $this->artisan('qeema:pipeline:health')
            ->expectsOutputToContain('scheduler')
            ->expectsOutputToContain('overall: ok')
            ->assertSuccessful();
    });

    it('succeeds even when degraded, so a real stop is not buried', function (): void {
        Cache::put('qeema:ml:circuit', true, 60);

        // It runs every five minutes. A task that fails whenever the platform
        // is merely behind trains whoever reads the log to ignore it.
        $this->artisan('qeema:pipeline:health')->assertSuccessful();
    });

    it('fails on request, for something that pages a human', function (): void {
        Cache::put('qeema:ml:circuit', true, 60);

        $this->artisan('qeema:pipeline:health', ['--strict' => true])->assertFailed();
    });
});

describe('what the public is told', function (): void {
    it('publishes the state of the pipeline', function (): void {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('pipeline.status', HealthCheck::OK)
            ->assertJsonPath('pipeline.scheduler.status', HealthCheck::OK);
    });

    it('never publishes a count', function (): void {
        Submission::factory()->count(3)->create([
            'status' => Submission::STATUS_NEEDS_REVIEW,
            'observed_at' => CarbonImmutable::now()->subDays(30),
        ]);

        $pipeline = $this->getJson('/api/v1/health')->json('pipeline');

        // "1,412 awaiting review" tells an honest observer very little and
        // tells somebody probing for a manipulation window how thin the
        // screening currently is.
        expect(json_encode($pipeline))->not->toContain('waiting')
            ->and($pipeline['review_queue'])->not->toHaveKey('waiting')
            ->and(array_keys($pipeline['review_queue']))->toBe(['status', 'age_seconds']);
    });

    it('keeps a degraded pipeline out of the HTTP status', function (): void {
        // This endpoint backs the container healthcheck. A pipeline that is
        // behind must not get the web container restarted underneath it.
        Cache::forget(SchedulerHeartbeatCommand::CACHE_KEY);

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('pipeline.status', HealthCheck::STALLED);
    });
});

describe('caching', function (): void {
    it('survives a cache written by a different version of the code', function (): void {
        // Redis is shared across every container and across a deploy, so a
        // serialised domain object outlives the class that defined it. This
        // shipped once and returned a 500 on the first real request:
        // __PHP_Incomplete_Class, from a value one version wrote and another
        // read. Only primitives go in now.
        $health = new PipelineHealth;

        $health->cachedChecks();

        $cached = Cache::get('qeema:pipeline:health');

        expect($cached)->toBeArray();

        foreach ($cached as $entry) {
            expect($entry)->toBeArray()
                ->and($entry)->toHaveKeys(['key', 'status', 'summary']);
        }
    });

    it('rebuilds checks from the cache rather than re-querying', function (): void {
        Cache::put('qeema:pipeline:health', [[
            'key' => 'scheduler',
            'status' => HealthCheck::STALLED,
            'summary' => 'from the cache',
            'age_seconds' => 42,
            'detail' => ['waiting' => 7],
        ]], 60);

        $checks = (new PipelineHealth)->cachedChecks();

        expect($checks)->toHaveCount(1)
            ->and($checks[0])->toBeInstanceOf(HealthCheck::class)
            ->and($checks[0]->status)->toBe(HealthCheck::STALLED)
            ->and($checks[0]->ageSeconds)->toBe(42)
            ->and($checks[0]->detail['waiting'])->toBe(7);
    });
});

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Country;
use App\Models\FxRate;
use App\Services\Fx\FxProviderRegistry;
use App\Services\Fx\FxRateResolver;
use App\Services\Fx\Providers\GenericHttpFxProvider;
use App\Services\Fx\Providers\ManualFxProvider;
use App\Support\Http\OutboundUrl;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Where the exchange rate comes from
|--------------------------------------------------------------------------
|
| The platform ships with no source for any currency, and that is the
| constraint rather than an omission: every feed worth trusting for these
| currencies sits behind an API key, and depending on one would give every
| deployment an account to create and a secret to keep (C1).
|
| So what is tested here is the machinery an operator configures — and, at least
| as importantly, what it refuses to do with a URL somebody typed into a
| configuration file.
|
*/

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function countryWithFx(array $fx, string $code = 'XF'): Country
{
    return Country::factory()->create([
        'code' => $code,
        'is_active' => true,
        'timezone' => 'UTC',
        'fx_config' => $fx,
    ]);
}

describe('choosing a provider', function (): void {
    it('ships manual entry as the default', function (): void {
        $country = countryWithFx([]);

        expect(app(FxProviderRegistry::class)->for($country)->key())->toBe(ManualFxProvider::KEY);
    });

    it('gives a country the provider it asked for', function (): void {
        $country = countryWithFx(['provider' => GenericHttpFxProvider::KEY]);

        expect(app(FxProviderRegistry::class)->for($country)->key())->toBe(GenericHttpFxProvider::KEY);
    });

    it('falls back to manual entry when a country names a provider that does not exist', function (): void {
        // A typo in a country file should degrade to "an operator types the
        // rate in" — a working system — not to an hourly task that fails and
        // takes every other country's rates down with it.
        $country = countryWithFx(['provider' => 'a_provider_that_does_not_exist']);

        expect(app(FxProviderRegistry::class)->for($country)->key())->toBe(ManualFxProvider::KEY);
    });

    it('has no opinion of its own about any real service', function (): void {
        // The registry knows how to read *a* JSON endpoint and nothing about
        // which one. A vendor adapter appearing here would put a third-party
        // dependency in every deployment.
        expect(app(FxProviderRegistry::class)->keys())
            ->toBe([ManualFxProvider::KEY, GenericHttpFxProvider::KEY]);
    });
});

describe('refusing a URL', function (): void {
    it('fetches only over http and https', function (): void {
        expect(OutboundUrl::isAllowed('file:///etc/passwd'))->toBeFalse()
            ->and(OutboundUrl::isAllowed('gopher://example.org/'))->toBeFalse();
    });

    it('refuses a loopback address', function (): void {
        expect(OutboundUrl::isAllowed('http://127.0.0.1/rates.json'))->toBeFalse();
    });

    it('refuses a private network address', function (): void {
        expect(OutboundUrl::isAllowed('http://10.0.0.5/rates.json'))->toBeFalse()
            ->and(OutboundUrl::isAllowed('http://192.168.1.1/rates.json'))->toBeFalse();
    });

    it('refuses the cloud metadata service', function (): void {
        // The one that matters. Left open, an operator — or anyone who can edit
        // a country file — reads the instance's credentials out of a log line.
        expect(OutboundUrl::isAllowed('http://169.254.169.254/latest/meta-data/'))->toBeFalse();
    });

    it('refuses a URL carrying credentials', function (): void {
        // They end up in logs, in error messages, and in a file under version
        // control.
        expect(OutboundUrl::isAllowed('https://user:secret@example.org/rates'))->toBeFalse();
    });

    it('does not treat an unresolvable host as an attack', function (): void {
        // The guard is a policy on addresses, not a liveness check on DNS. A
        // name with no address points nowhere, so it fails at connection time
        // with a message about the host — rather than a security refusal that
        // sends whoever typed it looking for the wrong problem.
        expect(OutboundUrl::isAllowed('https://qeema-nothing-resolves-here.invalid/rates'))->toBeTrue();
    });
});

describe('reading a configured source', function (): void {
    it('reads the rates at the paths the operator described', function (): void {
        Http::fake(['*' => Http::response([
            'as_of' => '2026-08-11',
            'rates' => ['official' => 4.85, 'parallel' => 7.6],
        ], 200)]);

        $country = countryWithFx([
            'provider' => GenericHttpFxProvider::KEY,
            'config' => [
                'url' => 'https://example.org/rates.json',
                'official_path' => 'rates.official',
                'parallel_path' => 'rates.parallel',
                'date_path' => 'as_of',
            ],
        ]);

        $quote = (new GenericHttpFxProvider)->fetch($country, CarbonImmutable::parse('2026-08-11'));

        expect($quote)->not->toBeNull()
            ->and($quote->officialRate)->toBe(4.85)
            ->and($quote->parallelRate)->toBe(7.6)
            // The whole payload is kept: when a published figure is questioned
            // months later, what the source actually said is what settles it.
            ->and($quote->raw)->toHaveKey('rates');
    });

    it('sends a token named by configuration but never written in it', function (): void {
        putenv('QEEMA_TEST_FX_TOKEN=Bearer sekret');

        Http::fake(['*' => Http::response(['parallel' => 7.6], 200)]);

        $country = countryWithFx([
            'provider' => GenericHttpFxProvider::KEY,
            'config' => [
                'url' => 'https://example.org/rates.json',
                'parallel_path' => 'parallel',
                'auth_header' => 'Authorization',
                'auth_token_env' => 'QEEMA_TEST_FX_TOKEN',
            ],
        ]);

        (new GenericHttpFxProvider)->fetch($country, CarbonImmutable::now());

        // The secret lives in the environment. A country file is in version
        // control, and a token in it is a token published.
        Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer sekret'));

        putenv('QEEMA_TEST_FX_TOKEN');
    });

    it('returns nothing when the response carries no rate at those paths', function (): void {
        Http::fake(['*' => Http::response(['something' => 'else'], 200)]);

        $country = countryWithFx([
            'provider' => GenericHttpFxProvider::KEY,
            'config' => ['url' => 'https://example.org/rates.json', 'parallel_path' => 'rates.parallel'],
        ]);

        expect((new GenericHttpFxProvider)->fetch($country, CarbonImmutable::now()))->toBeNull();
    });

    it('treats an unreachable source as an ordinary condition', function (): void {
        Http::fake(['*' => Http::response('nope', 503)]);

        $country = countryWithFx([
            'provider' => GenericHttpFxProvider::KEY,
            'config' => ['url' => 'https://example.org/rates.json', 'parallel_path' => 'parallel'],
        ]);

        // Null rather than an exception: the resolver already degrades through
        // this by falling back to the last usable rate and flagging it stale.
        expect((new GenericHttpFxProvider)->fetch($country, CarbonImmutable::now()))->toBeNull();
    });

    it('refuses a rate that is not a positive number', function (): void {
        Http::fake(['*' => Http::response(['parallel' => 0], 200)]);

        $country = countryWithFx([
            'provider' => GenericHttpFxProvider::KEY,
            'config' => ['url' => 'https://example.org/rates.json', 'parallel_path' => 'parallel'],
        ]);

        // Dividing a basket cost by zero, or by a negative, is not a figure.
        expect((new GenericHttpFxProvider)->fetch($country, CarbonImmutable::now()))->toBeNull();
    });
});

describe('the scheduled fetch', function (): void {
    it('stores what it fetched, against the country calendar day', function (): void {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 22:30:00', 'UTC'));

        Http::fake(['*' => Http::response(['parallel' => 7.6], 200)]);

        $country = countryWithFx([
            'provider' => GenericHttpFxProvider::KEY,
            'config' => ['url' => 'https://example.org/rates.json', 'parallel_path' => 'parallel'],
        ]);
        $country->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();

        $this->artisan('qeema:fx:fetch')->assertSuccessful();

        $rate = FxRate::query()->where('country_id', $country->id)->firstOrFail();

        // 22:30 UTC is already the next day fourteen hours east.
        expect($rate->rate_date->toDateString())->toBe('2026-08-12')
            ->and((float) $rate->parallel_rate)->toBe(7.6)
            ->and($rate->is_manual)->toBeFalse();
    });

    it('does nothing for a country that enters rates by hand', function (): void {
        countryWithFx(['provider' => ManualFxProvider::KEY]);

        $this->artisan('qeema:fx:fetch')
            ->expectsOutputToContain('entered by hand')
            ->assertSuccessful();

        expect(FxRate::query()->count())->toBe(0);
    });

    it('can be switched off entirely', function (): void {
        config()->set('qeema.fx.fetch_enabled', false);

        $this->artisan('qeema:fx:fetch')
            ->expectsOutputToContain('disabled')
            ->assertSuccessful();
    });

    it('does not duplicate a rate when it runs again', function (): void {
        Http::fake(['*' => Http::response(['parallel' => 7.6], 200)]);

        countryWithFx([
            'provider' => GenericHttpFxProvider::KEY,
            'config' => ['url' => 'https://example.org/rates.json', 'parallel_path' => 'parallel'],
        ]);

        $this->artisan('qeema:fx:fetch')->assertSuccessful();
        $this->artisan('qeema:fx:fetch')->assertSuccessful();

        expect(FxRate::query()->count())->toBe(1);
    });
});

describe('who wins when two sources disagree', function (): void {
    it('prefers the rate an operator typed over the one a machine fetched', function (): void {
        // Before any provider existed this resolved by recency alone, which
        // meant tonight's scheduled fetch would silently overrule the
        // correction somebody typed this afternoon after speaking to a trader.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 12:00:00', 'UTC'));

        $country = countryWithFx(['rate_type' => 'parallel', 'max_staleness_days' => 7]);

        FxRate::factory()->create([
            'country_id' => $country->id,
            'rate_date' => '2026-08-11',
            'source' => 'operator',
            'parallel_rate' => 7.60,
            'is_manual' => true,
            'fetched_at' => CarbonImmutable::parse('2026-08-11 09:00:00'),
        ]);

        FxRate::factory()->create([
            'country_id' => $country->id,
            'rate_date' => '2026-08-11',
            'source' => GenericHttpFxProvider::KEY,
            'parallel_rate' => 8.90,
            'is_manual' => false,
            // Later, and still not authoritative.
            'fetched_at' => CarbonImmutable::parse('2026-08-11 11:00:00'),
        ]);

        $resolved = (new FxRateResolver)->resolve($country, CarbonImmutable::parse('2026-08-11'));

        expect($resolved)->not->toBeNull()
            ->and($resolved->rate)->toBe(7.60);
    });

    it('keeps that precedence when falling back to an earlier day', function (): void {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-11 12:00:00', 'UTC'));

        $country = countryWithFx(['rate_type' => 'parallel', 'max_staleness_days' => 7]);

        FxRate::factory()->create([
            'country_id' => $country->id,
            'rate_date' => '2026-08-09',
            'source' => 'operator',
            'parallel_rate' => 7.60,
            'is_manual' => true,
            'fetched_at' => CarbonImmutable::parse('2026-08-09 09:00:00'),
        ]);

        FxRate::factory()->create([
            'country_id' => $country->id,
            'rate_date' => '2026-08-09',
            'source' => GenericHttpFxProvider::KEY,
            'parallel_rate' => 8.90,
            'is_manual' => false,
            'fetched_at' => CarbonImmutable::parse('2026-08-09 23:00:00'),
        ]);

        $resolved = (new FxRateResolver)->resolve($country, CarbonImmutable::parse('2026-08-11'));

        expect($resolved->rate)->toBe(7.60)
            ->and($resolved->isStale)->toBeTrue();
    });
});

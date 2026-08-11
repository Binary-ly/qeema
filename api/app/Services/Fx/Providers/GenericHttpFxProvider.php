<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Fx\Providers;

use App\Models\Country;
use App\Services\Fx\FxQuote;
use App\Services\Fx\FxRateProvider;
use App\Support\Http\OutboundUrl;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Reads rates from whatever JSON endpoint an operator points it at.
 *
 * There is no adapter for any particular service in this repository, and that
 * is a constraint rather than an omission. Constraint C1 says the platform
 * depends on no proprietary or paid third-party API, and the rate sources that
 * exist for the currencies this platform serves are mostly behind an API key.
 * Shipping an integration with one would make every deployment inherit a
 * dependency, an account and a secret — and would quietly make the "no
 * third-party keys, by construction" claim in SECURITY.md untrue.
 *
 * So the platform ships knowing how to read *a* JSON endpoint and nothing about
 * which one. An operator who has a source describes it in their country file:
 *
 *     fx:
 *       provider: generic_http
 *       config:
 *         url: https://example.org/rates.json
 *         parallel_path: data.parallel      # dot path into the response
 *         official_path: data.official
 *         date_path: data.as_of             # optional
 *         headers:
 *           Accept: application/json
 *         auth_header: Authorization        # optional
 *         auth_token_env: QEEMA_FX_TOKEN    # read from the environment
 *
 * The token is named, never written down: a secret belongs in the environment,
 * not in a country configuration file that lives in version control.
 */
final class GenericHttpFxProvider implements FxRateProvider
{
    public const KEY = 'generic_http';

    /**
     * A rates payload is a few hundred bytes. Anything approaching this is
     * either the wrong URL or something trying to occupy the process.
     */
    private const MAX_BYTES = 262_144;

    public function key(): string
    {
        return self::KEY;
    }

    public function fetch(Country $country, DateTimeInterface $date): ?FxQuote
    {
        /** @var array<string, mixed> $fx */
        $fx = $country->fx_config ?? [];
        /** @var array<string, mixed> $config */
        $config = $fx['config'] ?? [];

        $url = $config['url'] ?? null;

        if (! is_string($url) || $url === '') {
            $this->warn($country, 'no url configured');

            return null;
        }

        try {
            OutboundUrl::guard($url);
        } catch (InvalidArgumentException $e) {
            // Refusing is the whole point; saying so is what makes it fixable.
            $this->warn($country, $e->getMessage());

            return null;
        }

        try {
            $body = $this->download($url, $config);
        } catch (Throwable $e) {
            // Null, not an exception: a source being unreachable is an ordinary
            // condition that the resolver already degrades through — it falls
            // back to the last usable rate and flags it stale.
            $this->warn($country, $e->getMessage());

            return null;
        }

        return $this->toQuote($country, $config, $body, $date);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function download(string $url, array $config): array
    {
        $response = Http::withHeaders($this->headers($config))
            ->timeout((float) config('qeema.fx.http_timeout'))
            ->connectTimeout(5.0)
            // A redirect is a second URL that nothing has vetted, and following
            // one is the ordinary way an address check gets walked around.
            ->withoutRedirecting()
            ->get($url);

        if (! $response->successful()) {
            throw new InvalidArgumentException("Source returned HTTP {$response->status()}.");
        }

        $raw = $response->body();

        if (strlen($raw) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Source returned more data than a rates payload should be.');
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Source did not return a JSON object.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function headers(array $config): array
    {
        $headers = ['Accept' => 'application/json'];

        /** @var array<array-key, mixed> $configured */
        $configured = $config['headers'] ?? [];

        foreach ($configured as $name => $value) {
            // Keys come from YAML, where `10: value` is an integer key. A
            // header named "10" is nonsense, so it is dropped rather than
            // coerced into something the source will reject confusingly.
            if (is_string($name) && is_scalar($value)) {
                $headers[$name] = (string) $value;
            }
        }

        $header = $config['auth_header'] ?? null;
        $tokenEnv = $config['auth_token_env'] ?? null;

        if (is_string($header) && is_string($tokenEnv)) {
            // getenv() rather than env(): the variable's *name* comes from
            // country configuration, so it cannot be a config key, and env()
            // returns null once the container entrypoint has run
            // `config:cache` — which would silently drop the credential and
            // turn every fetch into an unexplained 401.
            $token = getenv($tokenEnv);

            if (is_string($token) && $token !== '') {
                $headers[$header] = $token;
            }
        }

        return $headers;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $body
     */
    private function toQuote(
        Country $country,
        array $config,
        array $body,
        DateTimeInterface $date,
    ): ?FxQuote {
        $official = $this->rateAt($body, $config['official_path'] ?? null);
        $parallel = $this->rateAt($body, $config['parallel_path'] ?? null);

        if ($official === null && $parallel === null) {
            $this->warn($country, 'response carried neither an official nor a parallel rate at the configured paths');

            return null;
        }

        return new FxQuote(
            date: $this->dateFrom($body, $config, $date),
            officialRate: $official,
            parallelRate: $parallel,
            source: self::KEY,
            baseCurrency: (string) (($country->fx_config['base_currency'] ?? null) ?: 'USD'),
            // The whole payload is kept. When a published figure is questioned
            // months later, what the source actually said that day is the only
            // thing that settles it.
            raw: $body,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function rateAt(array $body, mixed $path): ?float
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $value = Arr::get($body, $path);

        if (! is_numeric($value)) {
            return null;
        }

        $rate = (float) $value;

        // A non-positive rate is not a rate. Publishing one would divide a
        // basket cost by zero or flip its sign.
        return $rate > 0.0 ? $rate : null;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $config
     */
    private function dateFrom(array $body, array $config, DateTimeInterface $fallback): DateTimeInterface
    {
        $path = $config['date_path'] ?? null;

        if (! is_string($path) || $path === '') {
            return $fallback;
        }

        $value = Arr::get($body, $path);

        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            // A source with an unparseable date is still a usable rate; dating
            // it to the day it was fetched is the honest fallback.
            return $fallback;
        }
    }

    private function warn(Country $country, string $reason): void
    {
        Log::warning('Exchange rate source unusable', [
            'country' => $country->code,
            'provider' => self::KEY,
            'reason' => $reason,
        ]);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Ml;

use App\Models\CanonicalItem;
use App\Models\Country;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * HTTP client for the ML service.
 *
 * The Laravel application never imports an ML library; everything crosses this
 * boundary. That makes this class the single place where "the ML service is
 * having a bad day" has to be turned into something the platform can survive.
 *
 * It must never throw at the caller. A price observation that cannot be matched
 * is a submission awaiting review, not a failed request — and certainly not a
 * 500 shown to a reporter standing in a market. The circuit breaker exists so
 * that a service which is down stops being asked, rather than adding its
 * timeout to every submission for as long as the outage lasts.
 */
final class MlClient implements MlClientInterface
{
    private const BREAKER_KEY = 'qeema:ml:circuit';

    private const FAILURE_KEY = 'qeema:ml:failures';

    public function isAvailable(): bool
    {
        return ! Cache::has(self::BREAKER_KEY);
    }

    /**
     * Build the country's catalogue index before real traffic needs it.
     *
     * **Why this exists.** The matching service is stateless: it is handed the
     * catalogue on every request and embeds it on first sight, keyed by
     * fingerprint. That embedding is the expensive part — for a 675-variant
     * catalogue it is tens of seconds — and the ordinary request timeout is ten.
     * So the *first* submission after a deployment, or after any change to the
     * catalogue, times out. The circuit opens, and prices that should have
     * reached the index go to a human instead.
     *
     * Measured, not hypothesised: growing one country's catalogue from 133
     * variants to 675 made the end-to-end test fail with
     * `cURL error 28: Operation timed out after 10001 milliseconds` on the very
     * first match, and the loop never closed.
     *
     * Warming is therefore a deployment step, not an optimisation. It uses its
     * own generous timeout because it is doing work that legitimately takes
     * that long, and it deliberately does not touch the circuit breaker: a slow
     * first build is not a failing service.
     */
    public function warm(Country $country, ?float $timeout = null): bool
    {
        $config = config('qeema.ml');
        $variants = $this->catalogueFor($country);

        if ($variants === []) {
            return true;
        }

        try {
            return Http::baseUrl((string) $config['base_url'])
                ->timeout($timeout ?? (float) ($config['warm_timeout'] ?? 300.0))
                ->connectTimeout((float) $config['connect_timeout'])
                ->post('/v1/match', [
                    'text' => $variants[0]['text'] ?? 'warm',
                    'catalogue' => ['variants' => $variants],
                    'top_k' => 1,
                ])
                ->successful();
        } catch (Throwable $e) {
            Log::warning('Warming the matcher failed', [
                'country' => $country->code,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Resolve one piece of free text against a country's catalogue.
     *
     * Returns null when the service is unavailable or errored. Null means
     * "no opinion", which the caller turns into a review-queue entry — not
     * "no match", which would silently discard a valid observation.
     */
    public function match(Country $country, string $text, ?int $topK = null): ?MatchResult
    {
        if (! $this->isAvailable()) {
            return null;
        }

        $payload = [
            'text' => $text,
            'catalogue' => ['variants' => $this->catalogueFor($country)],
        ];

        if ($topK !== null) {
            $payload['top_k'] = $topK;
        }

        return $this->post('/v1/match', $payload, fn (array $body): MatchResult => MatchResult::fromArray($body));
    }

    /**
     * Resolve many texts against one catalogue.
     *
     * The catalogue is indexed once server-side for the whole batch, which is
     * the entire reason this exists — resolving a backlog one call at a time
     * would rebuild the index per submission.
     *
     * @param  list<string>  $texts
     * @return array<int, MatchResult>|null keyed by position in $texts
     */
    public function matchBatch(Country $country, array $texts, ?int $topK = null): ?array
    {
        if ($texts === [] || ! $this->isAvailable()) {
            return null;
        }

        $payload = [
            'texts' => $texts,
            'catalogue' => ['variants' => $this->catalogueFor($country)],
        ];

        if ($topK !== null) {
            $payload['top_k'] = $topK;
        }

        return $this->post('/v1/match/batch', $payload, function (array $body): array {
            /** @var list<array<string, mixed>> $results */
            $results = $body['results'] ?? [];

            return array_map(static fn (array $r): MatchResult => MatchResult::fromArray($r), $results);
        });
    }

    /**
     * Fit confidence calibration from human review outcomes.
     *
     * @param  list<float>  $scores
     * @param  list<bool>  $correct
     * @return array{fitted: bool, n_samples: int, reason: string}|null
     */
    public function calibrate(array $scores, array $correct): ?array
    {
        if (! $this->isAvailable()) {
            return null;
        }

        return $this->post(
            '/v1/match/calibrate',
            ['scores' => $scores, 'correct' => $correct],
            /** @return array{fitted: bool, n_samples: int, reason: string} */
            static fn (array $body): array => [
                'fitted' => (bool) ($body['fitted'] ?? false),
                'n_samples' => (int) ($body['n_samples'] ?? 0),
                'reason' => (string) ($body['reason'] ?? ''),
            ],
        );
    }

    /**
     * Score submissions for anomalies.
     *
     * Null on failure, like every other method here — an unscored submission is
     * one nobody has judged yet, not one judged clean. Treating a service
     * outage as a clean verdict would let bad data through precisely when the
     * system is least able to notice.
     *
     * @param  list<array<string, mixed>>  $observations
     * @return list<array<string, mixed>>|null
     */
    public function scoreAnomalies(array $observations): ?array
    {
        if ($observations === [] || ! $this->isAvailable()) {
            return null;
        }

        return $this->post(
            '/v1/anomaly/score',
            ['observations' => $observations],
            static function (array $body): array {
                /** @var list<array<string, mixed>> $results */
                $results = $body['results'] ?? [];

                // The service reports its version once, on the envelope; the
                // caller stores it per verdict. Without this every screened
                // observation is recorded against an unknown model version,
                // which makes a past verdict impossible to attribute when the
                // detector changes.
                $version = $body['model_version'] ?? null;

                return array_map(
                    static fn (array $verdict): array => $verdict + ['model_version' => $version],
                    $results,
                );
            },
        );
    }

    /**
     * Judge reporters on whether their prices sit away from their neighbours.
     *
     * @param  list<array{reporter_id: string, price: float, reference: float}>  $records
     * @return list<array<string, mixed>>|null
     */
    public function detectReporterBias(array $records): ?array
    {
        if ($records === [] || ! $this->isAvailable()) {
            return null;
        }

        return $this->post(
            '/v1/anomaly/reporter-bias',
            ['records' => $records],
            static function (array $body): array {
                /** @var list<array<string, mixed>> $results */
                $results = $body['results'] ?? [];

                return $results;
            },
        );
    }

    /**
     * Fit the nowcast models on observed history.
     *
     * @param  list<array<string, float>>  $features
     * @param  list<float>  $targets
     * @return array{trained: bool, n_samples: int, reason: string}|null
     */
    public function trainNowcast(Country $country, array $features, array $targets): ?array
    {
        if ($features === [] || ! $this->isAvailable()) {
            return null;
        }

        return $this->post(
            '/v1/nowcast/train',
            ['country' => $country->code, 'features' => $features, 'targets' => $targets],
            static fn (array $body): array => [
                'trained' => (bool) ($body['trained'] ?? false),
                'n_samples' => (int) ($body['n_samples'] ?? 0),
                'reason' => (string) ($body['reason'] ?? ''),
            ],
        );
    }

    /**
     * Impute prices for cells with no observation.
     *
     * @param  list<array<string, mixed>>  $requests
     * @return list<array<string, mixed>>|null
     */
    public function nowcast(Country $country, array $requests): ?array
    {
        if ($requests === [] || ! $this->isAvailable()) {
            return null;
        }

        $payload = ['country' => $country->code, 'requests' => $requests];

        return $this->post('/v1/nowcast/impute', $payload, static function (array $body): array {
            /** @var list<array<string, mixed>> $results */
            $results = $body['results'] ?? [];

            return $results;
        });
    }

    /**
     * The catalogue this country matches against.
     *
     * Sent per request so the ML service stays stateless. Cached because it
     * changes only when an item or variant does, and rebuilding it per
     * submission would dominate the cost of resolving a backlog.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogueFor(Country $country): array
    {
        return Cache::remember(
            "qeema:ml:catalogue:{$country->id}",
            now()->addMinutes(10),
            static function () use ($country): array {
                $rows = CanonicalItem::query()
                    ->where('country_id', $country->id)
                    ->where('is_active', true)
                    ->with('variants:id,canonical_item_id,text,normalized_text')
                    ->get(['id', 'code']);

                $variants = [];

                foreach ($rows as $item) {
                    foreach ($item->variants as $variant) {
                        $variants[] = [
                            'canonical_item_id' => $item->id,
                            'canonical_item_code' => $item->code,
                            'text' => $variant->text,
                            'normalized_text' => $variant->normalized_text,
                        ];
                    }
                }

                return $variants;
            },
        );
    }

    public static function forgetCatalogue(Country $country): void
    {
        Cache::forget("qeema:ml:catalogue:{$country->id}");
    }

    /**
     * Issue a request, recording success or failure against the breaker.
     *
     * @template T
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(array<string, mixed>): T  $transform
     * @return T|null
     */
    private function post(string $path, array $payload, callable $transform): mixed
    {
        $config = config('qeema.ml');

        try {
            $response = Http::baseUrl((string) $config['base_url'])
                ->timeout((float) $config['timeout'])
                ->connectTimeout((float) $config['connect_timeout'])
                ->retry(
                    (int) $config['retries'],
                    (int) $config['retry_delay_ms'],
                    // Retrying a 4xx is pointless: a malformed request will be
                    // malformed the second time too.
                    throw: false,
                )
                ->acceptJson()
                ->post($path, $payload);
        } catch (ConnectionException $e) {
            $this->recordFailure($path, $e->getMessage());

            return null;
        }

        if (! $response->successful()) {
            $this->recordFailure($path, "HTTP {$response->status()}");

            return null;
        }

        try {
            /** @var array<string, mixed> $body */
            $body = $response->json();

            $result = $transform($body);
        } catch (Throwable $e) {
            // A malformed response is a failure of the service, not of the
            // caller, and is counted as such — otherwise a service returning
            // garbage would never trip the breaker.
            $this->recordFailure($path, 'Malformed response: '.$e->getMessage());

            return null;
        }

        $this->recordSuccess();

        return $result;
    }

    private function recordSuccess(): void
    {
        Cache::forget(self::FAILURE_KEY);
    }

    /**
     * Count a failure and open the circuit once they accumulate.
     *
     * Consecutive failures, not a rolling rate: the thing being protected
     * against is a service that is *down*, and one that is merely occasionally
     * slow should not be cut off.
     */
    private function recordFailure(string $path, string $reason): void
    {
        $threshold = (int) config('qeema.ml.circuit_breaker.failure_threshold');
        $cooldown = (int) config('qeema.ml.circuit_breaker.cooldown_seconds');

        $failures = (int) Cache::get(self::FAILURE_KEY, 0) + 1;
        Cache::put(self::FAILURE_KEY, $failures, now()->addSeconds(max(60, $cooldown)));

        Log::warning('ML request failed', [
            'path' => $path,
            'reason' => $reason,
            'consecutive_failures' => $failures,
        ]);

        if ($failures >= $threshold) {
            Cache::put(self::BREAKER_KEY, true, now()->addSeconds($cooldown));
            Cache::forget(self::FAILURE_KEY);

            Log::error('ML circuit opened; degrading to human review', [
                'cooldown_seconds' => $cooldown,
            ]);
        }
    }

    /** Close the circuit immediately. Used by tests and by an operator. */
    public static function reset(): void
    {
        Cache::forget(self::BREAKER_KEY);
        Cache::forget(self::FAILURE_KEY);
    }
}

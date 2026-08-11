<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Pipeline;

/**
 * One answer to one question about whether the platform is still working.
 *
 * Deliberately carries two audiences in one object. `status` and `ageSeconds`
 * are safe to publish: they say whether the platform is keeping up, which any
 * consumer of the data has a legitimate interest in. `detail` holds the counts,
 * and stays behind the admin login — "1,412 submissions awaiting review" tells
 * an honest observer very little and tells somebody probing for a manipulation
 * window quite a lot about how thin the screening currently is.
 */
final readonly class HealthCheck
{
    public const OK = 'ok';

    public const DEGRADED = 'degraded';

    public const STALLED = 'stalled';

    /**
     * @param  array<string, mixed>  $detail  never published
     */
    public function __construct(
        public string $key,
        public string $status,
        public string $summary,
        public ?int $ageSeconds = null,
        public array $detail = [],
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     */
    public static function ok(string $key, string $summary, ?int $ageSeconds = null, array $detail = []): self
    {
        return new self($key, self::OK, $summary, $ageSeconds, $detail);
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    public static function degraded(string $key, string $summary, ?int $ageSeconds = null, array $detail = []): self
    {
        return new self($key, self::DEGRADED, $summary, $ageSeconds, $detail);
    }

    /**
     * Not "slow" but "stopped". Reserved for the conditions where the platform
     * has quietly ceased to publish rather than fallen behind.
     *
     * @param  array<string, mixed>  $detail
     */
    public static function stalled(string $key, string $summary, ?int $ageSeconds = null, array $detail = []): self
    {
        return new self($key, self::STALLED, $summary, $ageSeconds, $detail);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    /**
     * The publicly safe shape: a state and an age, never a count.
     *
     * @return array{status: string, age_seconds?: int}
     */
    public function toPublicArray(): array
    {
        return $this->ageSeconds === null
            ? ['status' => $this->status]
            : ['status' => $this->status, 'age_seconds' => $this->ageSeconds];
    }
}

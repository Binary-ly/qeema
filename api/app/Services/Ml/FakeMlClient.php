<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Ml;

use App\Models\Country;

/**
 * Test double for the ML service.
 *
 * Its responses are validated against contracts/ml-match-response.json by the
 * same suite that the real Python service is validated against. That is the
 * point: a hand-written fake drifts from the service it stands in for, every
 * test keeps passing, and the drift is only discovered in production.
 */
final class FakeMlClient implements MlClientInterface
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    private ?MatchResult $nextResult = null;

    private bool $available = true;

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function pretendUnavailable(): self
    {
        $this->available = false;

        return $this;
    }

    public function willReturn(MatchResult $result): self
    {
        $this->nextResult = $result;

        return $this;
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    public function willMatch(
        int $canonicalItemId,
        string $canonicalItemCode,
        float $confidence = 0.95,
        string $action = MatchResult::ACTION_AUTO_RESOLVE,
        array $candidates = [],
    ): self {
        return $this->willReturn(MatchResult::fromArray([
            'normalised_text' => 'stubbed',
            'action' => $action,
            'reason' => 'Stubbed by FakeMlClient.',
            'candidates' => $candidates !== [] ? $candidates : [[
                'canonical_item_id' => $canonicalItemId,
                'canonical_item_code' => $canonicalItemCode,
                'lexical_score' => 0.9,
                'semantic_score' => 0.0,
                'fused_score' => 0.9,
                'confidence' => $confidence,
                'matched_variant' => null,
            ]],
            'model_version' => 'fake-matcher-0.1.0',
            'calibrated' => false,
        ]));
    }

    public function match(Country $country, string $text, ?int $topK = null): ?MatchResult
    {
        $this->calls[] = ['method' => 'match', 'text' => $text];

        return $this->available ? $this->nextResult : null;
    }

    public function matchBatch(Country $country, array $texts, ?int $topK = null): ?array
    {
        $this->calls[] = ['method' => 'matchBatch', 'count' => count($texts)];

        if (! $this->available || $this->nextResult === null) {
            return null;
        }

        return array_map(fn (): MatchResult => $this->nextResult, $texts);
    }

    public function calibrate(array $scores, array $correct): ?array
    {
        $this->calls[] = ['method' => 'calibrate', 'count' => count($scores)];

        return $this->available
            ? ['fitted' => true, 'n_samples' => count($scores), 'reason' => 'Stubbed.']
            : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function catalogueFor(Country $country): array
    {
        return [];
    }
}

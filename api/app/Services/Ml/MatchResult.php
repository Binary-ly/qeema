<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Ml;

/**
 * The ML service's opinion about one piece of text.
 *
 * Shaped by contracts/ml-match-response.json, which both this side and the
 * Python side are tested against.
 */
final readonly class MatchResult
{
    public const ACTION_AUTO_RESOLVE = 'auto_resolve';

    public const ACTION_REVIEW = 'review';

    public const ACTION_REJECT = 'reject';

    /**
     * @param  list<array<string, mixed>>  $candidates
     */
    public function __construct(
        public string $normalisedText,
        public string $action,
        public string $reason,
        public array $candidates,
        public string $modelVersion,
        public bool $calibrated,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromArray(array $body): self
    {
        foreach (['normalised_text', 'action', 'reason', 'candidates', 'model_version'] as $key) {
            if (! array_key_exists($key, $body)) {
                throw new \InvalidArgumentException("ML response is missing '{$key}'.");
            }
        }

        $action = (string) $body['action'];

        // An unrecognised action must not be treated as auto_resolve by
        // accident. Anything unexpected degrades to human review.
        if (! in_array($action, [self::ACTION_AUTO_RESOLVE, self::ACTION_REVIEW, self::ACTION_REJECT], true)) {
            $action = self::ACTION_REVIEW;
        }

        /** @var list<array<string, mixed>> $candidates */
        $candidates = $body['candidates'];

        return new self(
            normalisedText: (string) $body['normalised_text'],
            action: $action,
            reason: (string) $body['reason'],
            candidates: $candidates,
            modelVersion: (string) $body['model_version'],
            calibrated: (bool) ($body['calibrated'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function best(): ?array
    {
        return $this->candidates[0] ?? null;
    }

    public function bestItemId(): ?int
    {
        $best = $this->best();

        return $best === null ? null : (int) $best['canonical_item_id'];
    }

    public function confidence(): float
    {
        $best = $this->best();

        return $best === null ? 0.0 : (float) $best['confidence'];
    }

    /**
     * Whether this result may resolve a submission without a human.
     *
     * Deliberately stricter than the service's own recommendation: an
     * uncalibrated confidence is not evidence, whatever number it carries, so
     * an uncalibrated deployment never auto-resolves on a model score. Exact
     * matches still do, because those arrive with the action already set and a
     * confidence that does not depend on the model.
     */
    public function shouldAutoResolve(): bool
    {
        return $this->action === self::ACTION_AUTO_RESOLVE && $this->bestItemId() !== null;
    }

    public function shouldReview(): bool
    {
        return $this->action === self::ACTION_REVIEW;
    }
}

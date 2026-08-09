<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Actions;

use App\Models\Submission;

/**
 * The outcome of recording a submission.
 *
 * `duplicate` is a first-class outcome rather than an error: an offline client
 * flushing its queue after a dropped connection *should* replay, and the
 * correct response is a calm "already have it", not a failure the client will
 * retry forever.
 */
final readonly class SubmissionResult
{
    private function __construct(
        public string $status,
        public ?Submission $submission,
        public ?string $reason = null,
    ) {}

    public static function accepted(Submission $submission): self
    {
        return new self('accepted', $submission);
    }

    public static function duplicate(Submission $submission): self
    {
        return new self('duplicate', $submission);
    }

    public static function rejected(string $reason): self
    {
        return new self('rejected', null, $reason);
    }

    public function isDuplicate(): bool
    {
        return $this->status === 'duplicate';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * HTTP status for this outcome.
     *
     * 201 for a new submission, 200 for a replay — the client treats both as
     * success and clears the item from its queue. A duplicate must never be a
     * 4xx, or a client with a stuck queue would retry it indefinitely.
     */
    public function httpStatus(): int
    {
        return match ($this->status) {
            'accepted' => 201,
            'duplicate' => 200,
            default => 403,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->submission === null) {
            return ['status' => $this->status, 'reason' => $this->reason];
        }

        return [
            'status' => $this->status,
            'id' => $this->submission->id,
            'client_idempotency_key' => $this->submission->client_idempotency_key,
            'observed_at' => $this->submission->observed_at?->toIso8601String(),
            'submission_status' => $this->submission->status,
        ];
    }
}

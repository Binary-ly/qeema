<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A price submission from the reporter app.
 *
 * This endpoint is unauthenticated by design — requiring a signup would
 * suppress exactly the participation the platform depends on — so validation
 * here is the only thing between the public internet and the submissions table.
 * It is correspondingly strict.
 */
final class StoreSubmissionRequest extends FormRequest
{
    /**
     * Sanity bound on a submitted price.
     *
     * Not a plausibility check — that is the anomaly detector's job, and it
     * needs to see implausible values to score them. This only rejects what
     * cannot be a price at all.
     */
    private const MAX_PRICE = 100_000_000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Device identity. Not a secret and not authentication: it exists so
            // a reputation can accrue without demanding a signup.
            'reporter_ref' => ['required', 'uuid'],

            'country' => ['required', 'string', 'size:2', Rule::exists('countries', 'code')],
            'location_slug' => ['required', 'string', 'max:96'],

            // Either free text or a picked catalogue item; at least one.
            'item_text' => ['required_without:canonical_item_code', 'nullable', 'string', 'max:500'],
            'canonical_item_code' => ['required_without:item_text', 'nullable', 'string', 'max:96'],

            'price' => ['required', 'numeric', 'gt:0', 'lt:'.self::MAX_PRICE],
            'currency' => ['nullable', 'string', 'size:3'],
            'unit' => ['nullable', 'string', 'max:64'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],

            // When the price was seen, which for an offline submission is not
            // when it arrived. Future dates are rejected; a modest tolerance
            // absorbs client clock skew.
            'observed_at' => ['nullable', 'date', 'before:'.now()->addDay()->toIso8601String()],

            // Client-generated, stable across retries. Paired with a unique
            // index, this is what makes offline replay safe.
            'client_idempotency_key' => ['required', 'uuid'],

            'device' => ['nullable', 'array'],
            'device.platform' => ['nullable', 'string', 'max:32'],
            'device.app_version' => ['nullable', 'string', 'max:32'],
            'device.queued_offline' => ['nullable', 'boolean'],

            // JPEG and PNG only, rather than Laravel's `image` rule, which
            // also admits SVG — a format that is a document, can carry script,
            // and has no picture data to separate metadata from. These two are
            // also the two whose metadata the platform knows how to remove.
            'photo' => ['nullable', 'file', 'mimes:jpeg,png', 'max:8192'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_idempotency_key.required' => 'A client_idempotency_key is required so a retried submission is not counted twice.',
            'item_text.required_without' => 'Provide either item_text or canonical_item_code.',
            'observed_at.before' => 'observed_at cannot be in the future.',
        ];
    }
}

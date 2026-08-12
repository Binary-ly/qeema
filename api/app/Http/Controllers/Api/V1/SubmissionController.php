<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\RecordSubmission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreSubmissionRequest;
use App\Models\Country;
use App\Support\Media\ImageMetadataStripper;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Inbound price submissions from the reporter app.
 *
 * This is the only write endpoint on the public API. It is unauthenticated
 * because requiring a signup would suppress the participation the platform runs
 * on; it is protected instead by strict validation, per-IP rate limiting, and
 * the fact that nothing submitted here reaches the published index without
 * passing anomaly screening first.
 */
final class SubmissionController extends Controller
{
    public function store(StoreSubmissionRequest $request, RecordSubmission $action): JsonResponse
    {
        $input = $request->validated();

        if ($request->hasFile('photo')) {
            $input['photo_path'] = $this->storePhoto($request);
        }

        $result = $action->handle($input);

        return response()->json($result->toArray(), $result->httpStatus());
    }

    /**
     * Store a photograph with its metadata removed.
     *
     * Stripped before it is written, never after: a file that reaches disk
     * carrying coordinates has already been backed up, and the window in which
     * it was exposed cannot be closed retrospectively.
     *
     * A photograph whose metadata cannot be removed is not stored at all. The
     * submission still counts — the price is the point, and the picture is
     * corroboration — so refusing the file costs a little evidence and refusing
     * to guess costs nothing.
     */
    private function storePhoto(StoreSubmissionRequest $request): ?string
    {
        $file = $request->file('photo');
        $binary = @file_get_contents($file->getRealPath());

        if ($binary === false) {
            return null;
        }

        $clean = (new ImageMetadataStripper)->strip($binary);

        if ($clean === null) {
            Log::warning('Rejected a photograph whose metadata could not be removed', [
                'mime' => $file->getMimeType(),
            ]);

            return null;
        }

        $path = 'submissions/'.Str::uuid()->toString().'.'.($file->guessExtension() ?? 'jpg');

        Storage::disk('local')->put($path, $clean);

        return $path;
    }

    /**
     * Everything the reporter app needs to work offline.
     *
     * Returned in one request so the app can cache a complete snapshot on first
     * run: a reporter who opens the app in a place with no signal must still be
     * able to pick a location and an item.
     */
    public function bootstrap(string $countryCode): JsonResponse
    {
        $country = Country::query()
            ->where('code', strtoupper($countryCode))
            ->where('is_active', true)
            ->firstOrFail();

        $basket = $country->baskets()->orderByDesc('version')->first();

        return response()->json([
            'country' => [
                'code' => $country->code,
                'name' => $country->name,
                'name_local' => $country->name_local,
                'currency' => [
                    'code' => $country->currency_code,
                    'symbol' => $country->currency_symbol,
                    'minor_units' => $country->currency_minor_units,
                ],
                'locales' => $country->locales,
                'default_locale' => $country->default_locale,
                'admin1_label' => $country->admin1_label,
            ],
            'locations' => $country->locations()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['slug', 'name', 'name_local', 'admin1_name', 'latitude', 'longitude'])
                ->all(),
            'items' => $basket?->items()
                ->with('canonicalItem:id,code,name_en,name_local,category,default_unit_code,default_quantity')
                ->get()
                ->map(fn ($entry): array => [
                    'code' => $entry->canonicalItem->code,
                    'name_en' => $entry->canonicalItem->name_en,
                    'name_local' => $entry->canonicalItem->name_local,
                    'category' => $entry->canonicalItem->category,
                    'unit' => $entry->unit_code,
                    'quantity' => (float) $entry->quantity,
                ])
                ->values()
                ->all() ?? [],
            'units' => $country->units()->get(['code', 'name', 'name_local'])->all(),
            'generated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'public, max-age=3600');
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\FxRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reference data: what is measured, where, and at what exchange rate.
 *
 * Published so a consumer can interpret an index figure rather than take it on
 * faith. A cost with no visible basket behind it is a number nobody can check.
 */
final class ReferenceController extends Controller
{
    public function countries(): JsonResponse
    {
        return response()->json([
            'data' => Country::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn (Country $c): array => [
                    'code' => $c->code,
                    'name' => $c->name,
                    'name_local' => $c->name_local,
                    'currency' => [
                        'code' => $c->currency_code,
                        'symbol' => $c->currency_symbol,
                        'minor_units' => $c->currency_minor_units,
                    ],
                    'locales' => $c->locales,
                    'exchange_rate_used' => $c->fxRateType(),
                ]),
        ])->header('Cache-Control', 'public, max-age=3600');
    }

    public function locations(string $countryCode): JsonResponse
    {
        $country = $this->country($countryCode);

        return response()->json([
            'data' => $country->locations()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->map(fn ($l): array => [
                    'slug' => $l->slug,
                    'name' => $l->name,
                    'name_local' => $l->name_local,
                    'admin1' => $l->admin1_name,
                    'latitude' => $l->latitude,
                    'longitude' => $l->longitude,
                    'population_estimate' => $l->population_estimate,
                ]),
        ])->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * The basket being costed, with its weights.
     *
     * Weights are published because they are a judgement, not a fact — a
     * consumer is entitled to disagree with how the basket is composed, and
     * cannot do that without seeing it.
     */
    public function basket(string $countryCode): JsonResponse
    {
        $country = $this->country($countryCode);
        $basket = $country->baskets()->orderByDesc('version')->firstOrFail();

        return response()->json([
            'name' => $basket->name,
            'version' => $basket->version,
            'effective_from' => $basket->effective_from->toDateString(),
            'effective_to' => $basket->effective_to?->toDateString(),
            'items' => $basket->items()->with('canonicalItem')->get()->map(fn ($i): array => [
                'code' => $i->canonicalItem->code,
                'name_en' => $i->canonicalItem->name_en,
                'name_local' => $i->canonicalItem->name_local,
                'category' => $i->category,
                'weight' => (float) $i->weight,
                'quantity' => (float) $i->quantity,
                'unit' => $i->unit_code,
            ]),
        ])->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * Exchange rate history, official and parallel.
     *
     * Both are published, and so is the premium between them: the gap is itself
     * a headline indicator of economic stress, and a platform that showed only
     * the rate it converts at would be withholding the more telling number.
     */
    public function fx(Request $request, string $countryCode): JsonResponse
    {
        $country = $this->country($countryCode);
        $from = $request->date('from') ?? now()->subDays(90);
        $to = $request->date('to') ?? now();

        return response()->json([
            'country' => $country->code,
            'base_currency' => 'USD',
            'used_by_index' => $country->fxRateType(),
            'data' => FxRate::query()
                ->where('country_id', $country->id)
                ->whereBetween('rate_date', [$from->toDateString(), $to->toDateString()])
                ->orderBy('rate_date')
                ->limit((int) config('qeema.api.max_page_size'))
                ->get()
                ->map(fn (FxRate $r): array => [
                    'date' => $r->rate_date->toDateString(),
                    'official' => $r->official_rate === null ? null : (float) $r->official_rate,
                    'parallel' => $r->parallel_rate === null ? null : (float) $r->parallel_rate,
                    'parallel_premium' => $r->parallelPremium(),
                    'source' => $r->source,
                ]),
        ]);
    }

    private function country(string $code): Country
    {
        return Country::query()
            ->where('code', strtoupper($code))
            ->where('is_active', true)
            ->firstOrFail();
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\IndexSnapshotResource;
use App\Models\Country;
use App\Models\IndexSnapshot;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The published affordability index.
 *
 * Unauthenticated by design (constraint C6): the data being open is the point.
 * The only protection is per-IP rate limiting.
 */
final class IndexController extends Controller
{
    /**
     * Latest snapshot per location for a country.
     */
    public function current(Request $request, string $countryCode): AnonymousResourceCollection
    {
        $country = $this->country($countryCode);

        // The most recent snapshot per location, not the most recent overall:
        // locations report at different rates, and taking a single global date
        // would silently drop every location that had not reported that day.
        $latest = IndexSnapshot::query()
            ->select('index_snapshots.*')
            ->join(
                DB::raw('(
                    SELECT location_id, MAX(snapshot_date) AS latest
                    FROM index_snapshots WHERE country_id = '.(int) $country->id.'
                    GROUP BY location_id
                ) newest'),
                function ($join): void {
                    $join->on('index_snapshots.location_id', '=', 'newest.location_id')
                        ->on('index_snapshots.snapshot_date', '=', 'newest.latest');
                },
            )
            ->where('index_snapshots.country_id', $country->id)
            ->with(['location', 'country', 'items.canonicalItem'])
            ->orderBy('index_snapshots.location_id')
            ->get();

        return IndexSnapshotResource::collection($latest);
    }

    /**
     * Time series for one location.
     */
    public function history(Request $request, string $locationSlug): AnonymousResourceCollection
    {
        $location = Location::query()->where('slug', $locationSlug)->firstOrFail();

        $from = $request->date('from') ?? now()->subDays(90);
        $to = $request->date('to') ?? now();

        $snapshots = IndexSnapshot::query()
            ->where('location_id', $location->id)
            ->whereBetween('snapshot_date', [$from->toDateString(), $to->toDateString()])
            ->with(['location', 'country'])
            ->orderBy('snapshot_date')
            ->limit((int) config('qeema.api.max_page_size'))
            ->get();

        return IndexSnapshotResource::collection($snapshots);
    }

    /**
     * One snapshot with its full item breakdown.
     */
    public function show(string $locationSlug, string $date): IndexSnapshotResource
    {
        $location = Location::query()->where('slug', $locationSlug)->firstOrFail();

        $snapshot = IndexSnapshot::query()
            ->where('location_id', $location->id)
            ->whereDate('snapshot_date', $date)
            ->with(['location', 'country', 'items.canonicalItem'])
            ->firstOrFail();

        return new IndexSnapshotResource($snapshot);
    }

    /**
     * Coverage and freshness, so a consumer can judge the data before using it.
     */
    public function coverage(string $countryCode): JsonResponse
    {
        $country = $this->country($countryCode);

        $snapshots = IndexSnapshot::query()
            ->where('country_id', $country->id)
            ->where('snapshot_date', '>=', now()->subDays(30)->toDateString())
            ->with('location')
            ->get();

        return response()->json([
            'country' => $country->code,
            'window_days' => 30,
            'locations' => $snapshots->groupBy('location_id')->map(function ($group) {
                $latest = $group->sortByDesc('snapshot_date')->first();

                return [
                    'slug' => $latest->location->slug,
                    'name' => $latest->location->name,
                    'latest_date' => $latest->snapshot_date->toDateString(),
                    'days_since_update' => (int) $latest->snapshot_date->diffInDays(now()),
                    'mean_coverage' => round((float) $group->avg('coverage_pct'), 4),
                    'mean_imputed_share' => round((float) $group->avg('imputed_share'), 4),
                    'snapshots' => $group->count(),
                ];
            })->values(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Bulk export, streamed.
     *
     * Streamed rather than assembled: a six-month national export is large
     * enough that building it in memory would turn one download into an outage
     * on a public, unauthenticated endpoint.
     */
    public function export(Request $request, string $countryCode): StreamedResponse
    {
        $country = $this->country($countryCode);
        $from = $request->date('from') ?? now()->subDays(90);
        $to = $request->date('to') ?? now();

        $filename = sprintf('qeema-%s-%s-to-%s.csv', strtolower($country->code), $from->toDateString(), $to->toDateString());

        return response()->streamDownload(function () use ($country, $from, $to): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'date', 'location_slug', 'location_name', 'cost_local', 'currency',
                'cost_usd', 'confidence_low', 'confidence_high',
                'coverage', 'imputed_share', 'comparable', 'quality',
                'fx_rate', 'fx_type', 'fx_is_stale',
            ]);

            IndexSnapshot::query()
                ->where('country_id', $country->id)
                ->whereBetween('snapshot_date', [$from->toDateString(), $to->toDateString()])
                ->with('location')
                ->orderBy('snapshot_date')
                ->chunk((int) config('qeema.api.export_chunk_size'), function ($chunk) use ($handle, $country): void {
                    foreach ($chunk as $snapshot) {
                        fputcsv($handle, [
                            $snapshot->snapshot_date->toDateString(),
                            $snapshot->location->slug,
                            $snapshot->location->name,
                            $snapshot->cost_local,
                            $country->currency_code,
                            $snapshot->cost_usd,
                            $snapshot->ci_low_local,
                            $snapshot->ci_high_local,
                            $snapshot->coverage_pct,
                            $snapshot->imputed_share,
                            $snapshot->isComparable() ? 'yes' : 'no',
                            $snapshot->qualityLabel(),
                            $snapshot->fx_rate_used,
                            $snapshot->fx_rate_type,
                            $snapshot->fx_is_stale ? 'yes' : 'no',
                        ]);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            // The licence travels with the data, because a CSV downloaded and
            // passed on loses every bit of context the API page carried.
            'X-Qeema-License' => 'CC-BY-4.0',
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

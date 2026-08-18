<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\IndexSnapshotResource;
use App\Models\Country;
use App\Models\IndexSnapshot;
use App\Models\Location;
use App\Support\Export\HxlTags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
        //
        // DISTINCT ON rather than a join against a grouped subquery. Both are
        // correct; this one is a single ordered walk of
        // (country_id, location_id, snapshot_date DESC) instead of a sequential
        // scan, a hash aggregate and then one index probe per location.
        // Measured on 35,712 rows: 3.35 ms against 4.32 ms, and the gap widens
        // with history because the aggregate side has to read every row.
        $latest = IndexSnapshot::query()
            // Laravel has no distinctOn() helper; DISTINCT ON is Postgres
            // syntax that must lead the select list, and its expression has to
            // match the leading ORDER BY column exactly.
            ->selectRaw('DISTINCT ON (index_snapshots.location_id) index_snapshots.*')
            ->join('baskets', 'baskets.id', '=', 'index_snapshots.basket_id')
            ->where('index_snapshots.country_id', $country->id)
            ->with(['location', 'country', 'basket', 'items.canonicalItem'])
            ->orderBy('index_snapshots.location_id')
            ->orderByDesc('index_snapshots.snapshot_date')
            // A revision leaves snapshots for both versions on the dates either
            // side of it, so a date is no longer unique per location. Highest
            // version wins, which is the basket actually in force — without this
            // the API can serve a figure from the superseded basket.
            ->orderByDesc('baskets.version')
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
            // One row per date. A basket revision leaves snapshots under both
            // versions for the dates around it, and a series that repeated a
            // date — once under each basket — would plot as a step that is not
            // in the data.
            ->selectRaw('DISTINCT ON (index_snapshots.snapshot_date) index_snapshots.*')
            ->join('baskets', 'baskets.id', '=', 'index_snapshots.basket_id')
            ->where('index_snapshots.location_id', $location->id)
            ->whereBetween('index_snapshots.snapshot_date', [$from->toDateString(), $to->toDateString()])
            ->with(['location', 'country', 'basket'])
            ->orderBy('index_snapshots.snapshot_date')
            ->orderByDesc('baskets.version')
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
            ->select('index_snapshots.*')
            ->join('baskets', 'baskets.id', '=', 'index_snapshots.basket_id')
            ->where('index_snapshots.location_id', $location->id)
            ->whereDate('index_snapshots.snapshot_date', $date)
            ->with(['location', 'country', 'basket', 'items.canonicalItem'])
            // The basket in force on that date, not whichever row was written
            // first. After a revision both exist, and the older one would win on
            // insertion order alone.
            ->orderByDesc('baskets.version')
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
     *
     * `?hxl=1` adds a HXL hashtag row beneath the header, which makes the file
     * directly ingestible by the humanitarian data ecosystem. See {@see HxlTags}
     * for what the tags mean and why the row is opt-in rather than always
     * present.
     */
    public function export(Request $request, string $countryCode): StreamedResponse
    {
        $country = $this->country($countryCode);
        $from = $request->date('from') ?? now()->subDays(90);
        $to = $request->date('to') ?? now();
        $hxl = $request->boolean('hxl');

        $filename = sprintf('qeema-%s-%s-to-%s.csv', strtolower($country->code), $from->toDateString(), $to->toDateString());

        return response()->streamDownload(function () use ($country, $from, $to, $hxl): void {
            $handle = fopen('php://output', 'wb');

            // The column list lives in HxlTags, keyed by header name, so the
            // header and the hashtag row are one declaration read two ways and
            // cannot drift when a column is added.
            fputcsv($handle, HxlTags::header());

            if ($hxl) {
                fputcsv($handle, HxlTags::row());
            }

            IndexSnapshot::query()
                // One row per location per date. A revision leaves snapshots
                // under both versions around the changeover, and a bulk file
                // that repeated a date would be read as two observations of the
                // same day. Ordered by location then date so each location's
                // series is contiguous in the file.
                ->selectRaw('DISTINCT ON (index_snapshots.location_id, index_snapshots.snapshot_date) index_snapshots.*')
                ->join('baskets', 'baskets.id', '=', 'index_snapshots.basket_id')
                ->where('index_snapshots.country_id', $country->id)
                ->whereBetween('index_snapshots.snapshot_date', [$from->toDateString(), $to->toDateString()])
                ->with(['location', 'basket'])
                ->orderBy('index_snapshots.location_id')
                ->orderBy('index_snapshots.snapshot_date')
                ->orderByDesc('baskets.version')
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
                            $snapshot->index_level,
                            $snapshot->basket->version,
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
            'X-Qeema-License' => (string) config('qeema.api.data_license'),
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

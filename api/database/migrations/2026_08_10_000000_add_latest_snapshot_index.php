<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Supports the busiest read on the platform: latest snapshot per location.
 *
 * `GET /countries/{code}/index/current` is what the dashboard and most API
 * consumers hit, and it resolves a per-location maximum date within one
 * country. The existing `(country_id, snapshot_date)` index does not serve
 * that: the grouping key is `location_id`, so Postgres fell back to scanning
 * every snapshot for the country to compute the aggregate.
 *
 * Measured on a 35,712-row table (three years, two countries, sixteen
 * locations): the inner aggregate scanned 17,856 rows. Harmless at demo scale
 * and linear in history, which is the wrong shape for the one query everybody
 * runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('index_snapshots', function (Blueprint $table): void {
            // Descending on date so the planner can walk straight to the most
            // recent row per location rather than sorting a group.
            $table->index(
                [DB::raw('country_id'), DB::raw('location_id'), DB::raw('snapshot_date DESC')],
                'index_snapshots_latest_per_location',
            );
        });
    }

    public function down(): void
    {
        Schema::table('index_snapshots', function (Blueprint $table): void {
            $table->dropIndex('index_snapshots_latest_per_location');
        });
    }
};

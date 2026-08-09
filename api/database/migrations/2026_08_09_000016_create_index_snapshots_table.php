<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The published output: one costed basket, for one location, on one date.
 *
 * The unique key makes recomputation an idempotent upsert, which is what allows
 * a correction to an old observation to safely ripple forward without
 * duplicating rows or requiring a delete-then-insert that would briefly serve a
 * missing snapshot to the public API.
 *
 * Three columns exist purely to stop the number being read as more certain than
 * it is: `coverage_pct`, `imputed_share`, and `fx_is_stale`. A cost figure with
 * 40% of its weight imputed against a nine-day-old exchange rate is a legitimate
 * estimate, but publishing it without saying so would not be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('index_snapshots', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            // Records which basket version produced this figure.
            $table->foreignId('basket_id')->constrained()->restrictOnDelete();

            $table->date('snapshot_date');

            $table->decimal('cost_local', 18, 4);
            $table->decimal('cost_usd', 18, 4)->nullable()
                ->comment('Null when no usable FX rate was available');

            $table->decimal('normalized_index', 12, 4)->nullable()
                ->comment('100 at the base date, chain-linked across basket versions');

            // Weight-based, not count-based: a missing high-weight staple must
            // not look like a missing low-weight pencil.
            $table->decimal('coverage_pct', 5, 4)
                ->comment('Share of basket weight backed by real observations');
            $table->decimal('imputed_share', 5, 4)->default(0)
                ->comment('Share of basket weight that was estimated');

            $table->decimal('ci_low_local', 18, 4)->nullable();
            $table->decimal('ci_high_local', 18, 4)->nullable();

            $table->decimal('fx_rate_used', 18, 8)->nullable();
            $table->string('fx_rate_type', 16)->nullable()->comment('parallel | official | manual');
            $table->date('fx_rate_date')->nullable();
            $table->boolean('fx_is_stale')->default(false)
                ->comment('True when the rate predates the snapshot date');

            $table->unsignedSmallInteger('observed_item_count')->default(0);
            $table->unsignedSmallInteger('total_item_count')->default(0);

            // Set when an upstream observation changes; a queued job recomputes.
            $table->boolean('is_stale')->default(false);

            $table->timestampTz('computed_at')->nullable();
            $table->string('model_version')->nullable();

            $table->timestamps();

            $table->unique(['location_id', 'basket_id', 'snapshot_date'], 'index_snapshots_unique');
            $table->index(['country_id', 'snapshot_date']);
            $table->index(['location_id', 'snapshot_date']);
            // Drives the recomputation queue.
            $table->index('is_stale');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('index_snapshots');
    }
};

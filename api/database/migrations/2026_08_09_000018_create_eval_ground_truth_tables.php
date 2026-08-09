<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ground-truth labels for the synthetic data generator.
 *
 * These live in a dedicated `qeema_eval` schema, never in `public`, because a
 * label leaking into a published response would be a credibility failure the
 * project could not recover from. Physical separation makes it structurally
 * impossible rather than merely unlikely: the API's database role is granted no
 * access to this schema, so even a mistaken join fails loudly.
 *
 * `gt_prices` carries the true price for every location/item/date pair including
 * days with zero observations — which is precisely what makes imputation error
 * measurable rather than assumed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE SCHEMA IF NOT EXISTS qeema_eval');

        // `migrate:fresh` drops tables in the connection's search_path, which
        // does not include qeema_eval — so these would survive a rebuild and
        // make the next migration fail on "relation already exists". Dropping
        // them here keeps a fresh rebuild genuinely fresh.
        Schema::dropIfExists('qeema_eval.gt_prices');
        Schema::dropIfExists('qeema_eval.gt_submissions');

        Schema::create('qeema_eval.gt_submissions', function ($table): void {
            $table->uuid('submission_id')->primary();
            $table->unsignedBigInteger('true_canonical_item_id')->nullable();
            $table->decimal('true_price_per_base_unit', 18, 6)->nullable();

            $table->boolean('is_erroneous')->default(false);
            $table->boolean('is_manipulated')->default(false);
            $table->string('error_type', 48)->nullable()
                ->comment('unit_confusion | decimal_slip | wrong_currency | stale_copy | none');

            $table->timestampTz('created_at')->useCurrent();

            $table->index('true_canonical_item_id');
            $table->index(['is_erroneous', 'is_manipulated']);
        });

        Schema::create('qeema_eval.gt_prices', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('location_id');
            $table->unsignedBigInteger('canonical_item_id');
            $table->date('price_date');
            $table->decimal('true_price_per_base_unit', 18, 6);

            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['location_id', 'canonical_item_id', 'price_date'], 'gt_prices_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qeema_eval.gt_prices');
        Schema::dropIfExists('qeema_eval.gt_submissions');
    }
};

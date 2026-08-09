<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A validated, unit-normalised price point. The only thing the index reads.
 *
 * Two columns deserve explanation.
 *
 * `normalized_price_per_base_unit` is what the estimator actually consumes.
 * Storing it rather than recomputing keeps the index query simple and means a
 * later change to unit conversion cannot silently restate history.
 *
 * `reputation_at_time` snapshots the reporter's reputation as of ingestion. The
 * estimator weights observations by it, and using the *current* reputation
 * instead would make recomputing an old snapshot produce a different answer
 * every time a reporter's score moved. Recomputation must be deterministic.
 *
 * Corrections supersede rather than mutate: the superseded row stays, with
 * is_valid = false and superseded_by_id pointing at its replacement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_observations', function (Blueprint $table): void {
            $table->id();

            $table->uuid('submission_id')->unique();
            $table->foreign('submission_id')->references('id')->on('submissions')->cascadeOnDelete();

            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('canonical_item_id')->constrained()->restrictOnDelete();

            $table->decimal('price', 18, 4)->comment('As submitted, in currency_code');
            $table->string('currency_code', 3);
            $table->string('unit_code', 24);
            $table->decimal('quantity', 12, 4);

            $table->decimal('normalized_price_per_base_unit', 18, 6)
                ->comment('What the estimator consumes; comparable across units');

            $table->date('observed_on')->comment('Date the index buckets this into');
            $table->timestampTz('observed_at');

            $table->foreignId('reporter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_id')->constrained()->restrictOnDelete();

            $table->decimal('reputation_at_time', 5, 4)->default(0.5)
                ->comment('Frozen at ingestion so recomputation is deterministic');

            $table->boolean('is_valid')->default(true);
            $table->foreignId('superseded_by_id')->nullable()
                ->constrained('price_observations')->nullOnDelete();

            $table->timestamps();

            // The hot path: "every valid observation of this item, in this
            // location, within this date window".
            $table->index(
                ['location_id', 'canonical_item_id', 'observed_on', 'is_valid'],
                'price_obs_index_lookup'
            );
            $table->index(['country_id', 'observed_on']);
            $table->index(['canonical_item_id', 'observed_on']);
            $table->index('reporter_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_observations');
    }
};

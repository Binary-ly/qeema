<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-item breakdown behind a snapshot.
 *
 * This is where `is_imputed` originates, and it is the most important boolean in
 * the schema. From here it is carried unchanged into every API response and every
 * chart. A value the system estimated must never be presented as one it observed;
 * that single confusion would do more damage to the platform's credibility than
 * any amount of imputation error.
 *
 * `source_observation_ids` completes the provenance chain: a published item price
 * points back at the exact observations that produced it, which point back at
 * submissions, which hold the original raw text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('index_snapshot_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('index_snapshot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('canonical_item_id')->constrained()->restrictOnDelete();

            $table->decimal('unit_price_local', 18, 6)
                ->comment('Estimated price per base unit');
            $table->decimal('weight', 8, 6);
            $table->decimal('quantity', 12, 4);
            $table->decimal('contribution_local', 18, 4)
                ->comment('quantity * unit_price_local');

            $table->boolean('is_imputed')->default(false)
                ->comment('True when estimated rather than observed. Never hide this.');
            $table->string('imputation_method', 48)->nullable()
                ->comment('lightgbm_quantile | fallback_admin1_median | fallback_national');

            $table->decimal('ci_low', 18, 6)->nullable();
            $table->decimal('ci_high', 18, 6)->nullable();

            $table->unsignedSmallInteger('observation_count')->default(0)
                ->comment('How many observations backed this estimate');

            $table->jsonb('source_observation_ids')->nullable()
                ->comment('Provenance: which observations produced this price');

            $table->timestamps();

            $table->unique(['index_snapshot_id', 'canonical_item_id'], 'index_snapshot_items_unique');
            $table->index(['canonical_item_id', 'is_imputed']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('index_snapshot_items');
    }
};

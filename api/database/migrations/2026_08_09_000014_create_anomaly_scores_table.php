<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why the system does or does not trust a submission.
 *
 * `reasons` holds human-readable explanations, not just a number. A reviewer
 * looking at a flagged submission needs to see "price is 8x the local median for
 * this item" — an opaque score of 0.94 gives them nothing to act on, and a
 * reviewer who cannot understand the flag will either rubber-stamp it or ignore
 * it, both of which defeat the review queue.
 *
 * `layer_scores` keeps the three detection layers separate so it is possible to
 * tell which one fired and to measure each independently against the labelled
 * synthetic data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anomaly_scores', function (Blueprint $table): void {
            $table->id();

            $table->uuid('submission_id');
            $table->foreign('submission_id')->references('id')->on('submissions')->cascadeOnDelete();

            $table->decimal('score', 5, 4)->comment('0 = clean, 1 = almost certainly bad');
            $table->string('verdict', 16)->comment('clean | suspect | rejected');

            $table->jsonb('reasons')->nullable()
                ->comment('Human-readable explanations shown to reviewers');
            $table->jsonb('layer_scores')->nullable()
                ->comment('Per-layer scores: bounds, robust statistics, isolation forest');

            $table->string('model_version')->nullable();

            $table->timestampTz('created_at')->useCurrent();

            $table->index(['submission_id', 'created_at']);
            $table->index(['verdict', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anomaly_scores');
    }
};

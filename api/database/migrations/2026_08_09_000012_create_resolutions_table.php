<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The link from a raw submission to a canonical item, and how confident we are.
 *
 * `candidates` keeps the full top-k the matcher returned, not just the winner.
 * When a human later overrules the match, that record is what tells us whether
 * the right answer was ranked second or was not retrieved at all — which are
 * very different failures needing very different fixes.
 *
 * `model_version` is recorded so a change in matching behaviour can be traced
 * to the model that produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resolutions', function (Blueprint $table): void {
            $table->id();

            $table->uuid('submission_id')->unique();
            $table->foreign('submission_id')->references('id')->on('submissions')->cascadeOnDelete();

            $table->foreignId('canonical_item_id')->nullable()
                ->constrained()->nullOnDelete();

            $table->string('method', 32)
                ->comment('exact | lexical | semantic | fused | human | rule');
            $table->decimal('confidence', 5, 4)->nullable()
                ->comment('Calibrated probability the match is correct');

            $table->jsonb('candidates')->nullable()
                ->comment('Top-k the matcher considered, with per-signal scores');

            $table->boolean('reviewed')->default(false);
            $table->foreignId('reviewed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();

            $table->string('model_version')->nullable();

            $table->timestamps();

            $table->index(['reviewed', 'confidence']);
            $table->index('canonical_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resolutions');
    }
};

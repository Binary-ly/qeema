<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned basket definitions.
 *
 * Baskets change: an item becomes unavailable, a weight is revised, a new
 * category is added. Versioning with effective dates means a historical index
 * value can always be recomputed against the basket that was actually in force
 * on that date, rather than silently re-costed against today's definition.
 *
 * Snapshots record which basket version produced them, and a version change is
 * chain-linked so the published series has no artificial jump.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('baskets', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('country_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->unsignedInteger('version')->default(1);

            $table->date('effective_from');
            $table->date('effective_to')->nullable()
                ->comment('Null means currently in force');

            $table->text('notes')->nullable()
                ->comment('Why this version differs from the previous one');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'version']);
            $table->index(['country_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baskets');
    }
};

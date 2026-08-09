<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Known alternative names for a canonical item — the matcher's memory.
 *
 * This is a table rather than the text[] column the brief sketched, because
 * every human review decision becomes a new variant and each one needs
 * provenance: which submission produced it, who approved it, how often it has
 * matched since. An array column carries none of that, and cannot be trigram
 * indexed per element.
 *
 * `normalized_text` holds the Arabic-normalised form (diacritics and tatweel
 * stripped, alef and yaa variants unified, Arabic-Indic digits folded to ASCII).
 * Matching runs against that column, so the same normalisation must be applied
 * to an incoming query before comparison.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canonical_item_variants', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('canonical_item_id')->constrained()->cascadeOnDelete();

            $table->text('text')->comment('Variant exactly as seen');
            $table->text('normalized_text')->comment('Normalised form; matching runs on this');
            $table->string('locale', 12)->nullable();

            $table->string('source', 32)->default('seed')
                ->comment('seed | human_review | scraper | partner');

            $table->uuid('created_from_submission_id')->nullable();
            $table->foreign('created_from_submission_id')
                ->references('id')->on('submissions')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->unsignedInteger('times_matched')->default(0)
                ->comment('How often this variant has resolved a submission');

            $table->timestamps();

            $table->unique(['canonical_item_id', 'normalized_text']);
            $table->index('source');
        });

        // The lexical half of the matcher searches this column.
        DB::statement('CREATE INDEX canonical_item_variants_normalized_trgm
                       ON canonical_item_variants USING gin (normalized_text gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_item_variants');
    }
};

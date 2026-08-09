<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The controlled vocabulary every raw price observation resolves to.
 *
 * Two indexes here carry the matching pipeline:
 *   - a trigram index on the names, for lexical retrieval;
 *   - an HNSW index on the embedding, for semantic retrieval.
 *
 * The embedding dimension is fixed at 768 to match multilingual-e5-base. An
 * operator swapping the model must change it here too, so the column carries a
 * comment saying so and `embedding_model` records what actually produced each
 * vector.
 */
return new class extends Migration
{
    private const EMBEDDING_DIMENSIONS = 768;

    public function up(): void
    {
        Schema::create('canonical_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('country_id')->constrained()->cascadeOnDelete();

            $table->string('code', 96)->comment('Stable slug used by the public API');
            $table->string('name_en');
            $table->string('name_local')->nullable();
            $table->string('category', 64)->index();

            $table->string('default_unit_code', 24);
            $table->decimal('default_quantity', 12, 4)->default(1);

            // Nullable because an item exists before it has been embedded; the
            // embedding job fills these in and refreshes them when a variant is
            // added or the model changes.
            $table->vector('embedding', self::EMBEDDING_DIMENSIONS)->nullable()
                ->comment('multilingual-e5-base, 768 dims; change with the model');
            $table->string('embedding_model')->nullable();
            $table->timestampTz('embedding_updated_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'code']);
            $table->index(['country_id', 'is_active']);
        });

        // Trigram indexes for lexical matching. GIN rather than GiST: the table
        // is small and read-heavy, where GIN's faster lookups win.
        DB::statement('CREATE INDEX canonical_items_name_en_trgm
                       ON canonical_items USING gin (name_en gin_trgm_ops)');
        DB::statement('CREATE INDEX canonical_items_name_local_trgm
                       ON canonical_items USING gin (name_local gin_trgm_ops)');

        // HNSW over cosine distance. Embeddings are L2-normalised before
        // storage, so cosine and inner product rank identically; cosine is used
        // because it is the metric e5 was trained with.
        //
        // HNSW rather than IVFFlat deliberately: IVFFlat needs representative
        // data present at build time to cluster on, and this table is empty when
        // migrations run. HNSW builds incrementally and needs no training pass.
        DB::statement('CREATE INDEX canonical_items_embedding_hnsw
                       ON canonical_items USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('canonical_items');
    }
};

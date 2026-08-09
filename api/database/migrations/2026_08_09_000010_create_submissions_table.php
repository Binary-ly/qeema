<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The raw inbound observation. The root of every provenance chain.
 *
 * Nothing in this table is ever rewritten. If a price is corrected, a new row
 * supersedes the old one downstream; the original raw text stays exactly as it
 * arrived. That is what lets anyone audit a published index number back to what
 * a person actually typed.
 *
 * Three timestamps, because for an offline submission they genuinely differ:
 *   observed_at  — when the price was seen in the shop
 *   collected_at — when the reporter entered it into the app
 *   ingested_at  — when it reached the server, possibly days later after sync
 * Using the wrong one would place prices on the wrong day and distort the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->restrictOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_id')->constrained()->restrictOnDelete();
            $table->foreignId('ingestion_batch_id')->nullable()->constrained()->nullOnDelete();

            // Never normalised away, never cleaned in place. The matcher reads
            // it, but this column keeps the original spelling, dialect and
            // script exactly as submitted.
            $table->text('raw_text');
            $table->decimal('raw_price', 18, 4);
            $table->string('currency_code', 3);
            $table->string('raw_unit', 64)->nullable();
            $table->decimal('raw_quantity', 12, 4)->nullable();

            $table->string('photo_path')->nullable();

            $table->timestampTz('observed_at');
            $table->timestampTz('collected_at');
            $table->timestampTz('ingested_at')->useCurrent();

            $table->jsonb('device_metadata')->nullable()
                ->comment('App version, platform, whether it was queued offline');

            // Client-generated UUID. Paired with the unique index below, this is
            // what makes offline replay safe: a submission retried after a flaky
            // connection collides here instead of being counted twice.
            $table->uuid('client_idempotency_key')->nullable();

            $table->string('status', 32)->default('pending')
                ->comment('pending | resolved | needs_review | rejected');

            $table->timestamps();

            $table->unique(['reporter_id', 'client_idempotency_key']);
            $table->index(['country_id', 'observed_at']);
            $table->index(['location_id', 'observed_at']);
            $table->index(['status', 'created_at']);
            $table->index('ingestion_batch_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};

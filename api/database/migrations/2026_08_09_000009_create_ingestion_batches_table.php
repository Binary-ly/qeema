<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One partner file upload or one scraper run.
 *
 * `checksum` gives idempotency: re-uploading a file that has already been
 * processed is recognised and refused rather than silently doubling every price
 * in it. `error_report` holds per-row failures so a malformed file produces an
 * actionable list for the partner instead of a 500.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingestion_batches', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->string('filename')->nullable();
            $table->string('checksum', 64)->nullable()
                ->comment('SHA-256 of the uploaded file; makes re-upload a no-op');

            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('accepted_count')->default(0);
            $table->unsignedInteger('rejected_count')->default(0);

            $table->string('status', 32)->default('pending')
                ->comment('pending | processing | completed | failed');

            $table->jsonb('column_mapping')->nullable()
                ->comment('Which incoming column maps to which field');
            $table->jsonb('error_report')->nullable()
                ->comment('Per-row validation failures, for the partner to fix');

            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->timestamps();

            $table->unique(['source_id', 'checksum']);
            $table->index(['source_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_batches');
    }
};

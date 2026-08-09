<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where data came from.
 *
 * Every submission points at a source, so a published figure can always be
 * traced to its origin — a named partner, a specific scraper, or the reporter
 * app. `license` and `url` are recorded because republishing partner and scraped
 * data under an open licence requires knowing what the incoming licence was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sources', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('country_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32)->comment('reporter | partner_upload | scraper');
            $table->string('name');
            $table->string('slug', 96);

            $table->string('url')->nullable();
            $table->string('license')->nullable()
                ->comment('Incoming licence; needed before republishing');
            $table->string('contact')->nullable();

            $table->jsonb('config')->nullable()
                ->comment('Scraper settings, rate limits and resume cursor');

            $table->timestampTz('last_run_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'slug']);
            $table->index(['country_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sources');
    }
};

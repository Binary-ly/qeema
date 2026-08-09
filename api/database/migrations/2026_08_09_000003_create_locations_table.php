<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Places the index is computed for.
 *
 * Latitude and longitude are stored because nowcasting needs spatial neighbours.
 * They are computed by haversine distance from these columns — constraint C1
 * rules out a commercial geocoding or routing service, and for finding nearby
 * towns straight-line distance is entirely adequate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('country_id')->constrained()->cascadeOnDelete();

            $table->string('admin1_name')->nullable();
            $table->string('admin1_code', 32)->nullable();
            $table->string('admin2_name')->nullable();
            $table->string('admin2_code', 32)->nullable();

            $table->string('name');
            $table->string('name_local')->nullable();
            $table->string('slug', 96);

            $table->decimal('latitude', 9, 6)->nullable();
            $table->decimal('longitude', 9, 6)->nullable();

            $table->unsignedBigInteger('population_estimate')->nullable()
                ->comment('Used to weight national aggregates, never the local index');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['country_id', 'slug']);
            $table->index(['country_id', 'is_active']);
            $table->index(['country_id', 'admin1_code']);
            // Nowcasting scans coordinates to find neighbouring locations.
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};

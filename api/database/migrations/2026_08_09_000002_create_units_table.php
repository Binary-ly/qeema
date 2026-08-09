<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Units of measure and their conversion factors.
 *
 * This table is what makes "1 kg of rice", "500 g of rice" and "a 2 kg bag of
 * rice" comparable. Every observation is normalised to a price per base unit
 * before it may enter the index; without that, the index would be averaging
 * numbers that are not the same kind of thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id();

            // Null country_id means a unit shared by every country.
            $table->foreignId('country_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('code', 24);
            $table->string('name');
            $table->string('name_local')->nullable();

            $table->string('dimension', 16)
                ->comment('mass | volume | count | length');

            // Conversion is expressed against a base unit within the dimension
            // (kg for mass, l for volume, piece for count).
            $table->string('base_unit_code', 24);
            $table->decimal('factor_to_base', 18, 9)
                ->comment('multiply a quantity in this unit by this to get base units');

            $table->timestamps();

            $table->unique(['country_id', 'code']);
            $table->index('dimension');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};

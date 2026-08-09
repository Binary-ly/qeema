<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exchange rates, official and parallel.
 *
 * Both are stored, and which one the index uses is country configuration. This
 * distinction is the whole point of the platform in a crisis economy: the
 * official rate is the one published, and the parallel rate is the one people
 * can actually transact at. Converting a basket cost at the official rate would
 * produce a USD figure that looks reassuring and is unobtainable.
 *
 * `raw` keeps the provider's original response so a disputed rate can be
 * audited rather than argued about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rates', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('country_id')->constrained()->cascadeOnDelete();

            $table->date('rate_date');

            $table->decimal('official_rate', 18, 8)->nullable()
                ->comment('Local currency per unit of base currency');
            $table->decimal('parallel_rate', 18, 8)->nullable()
                ->comment('Street rate; what the index uses by default');

            $table->string('base_currency', 3)->default('USD');
            $table->string('source', 64)->comment('Provider slug that supplied this');

            $table->boolean('is_manual')->default(false)
                ->comment('Entered by an operator rather than fetched');

            $table->jsonb('raw')->nullable()->comment('Provider response, for audit');

            $table->timestampTz('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['country_id', 'rate_date', 'source']);
            $table->index(['country_id', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rates');
    }
};

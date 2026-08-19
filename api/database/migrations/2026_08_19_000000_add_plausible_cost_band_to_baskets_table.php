<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this basket ought to cost, so a figure of the wrong size has something
 * to be wrong against.
 *
 * A published basket once read about ten times what a five-person household
 * spends in a month, and nothing objected, because nothing in the system held
 * an opinion about what the answer should look like. Every check asked whether
 * the pipeline was moving; none asked whether what came out was credible.
 *
 * Nullable, because a country that has not yet worked out a defensible range
 * should say nothing rather than assert a guess. The health check skips those
 * rather than inventing a band for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('baskets', function (Blueprint $table): void {
            $table->jsonb('plausible_cost_band')->nullable()
                ->comment('{min,max} in local currency, per the country file; justified by a cited outside source');
        });
    }

    public function down(): void
    {
        Schema::table('baskets', function (Blueprint $table): void {
            $table->dropColumn('plausible_cost_band');
        });
    }
};

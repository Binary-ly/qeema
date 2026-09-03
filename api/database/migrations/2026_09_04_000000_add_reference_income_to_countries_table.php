<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| A sourced income to set the basket against
|--------------------------------------------------------------------------
|
| The index published a cost and called itself an affordability index. A cost
| is a number; affordability is that number against what a household has, and
| the page never said what that was. A reader who did not already know local
| wages could not tell whether the figure for a child's month was a rounding
| error or a catastrophe.
|
| One reference income per country, from the country file, with its source
| beside it — the legal minimum monthly wage, where a country has one. Stored
| raw, as the index and FX settings are, so what the page says can be traced
| to the law that set it.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            $table->jsonb('reference_income')->nullable()->after('index_config')
                ->comment('A sourced monthly income the basket cost is compared against; from countries/*.yaml');
        });
    }

    public function down(): void
    {
        Schema::table('countries', function (Blueprint $table): void {
            $table->dropColumn('reference_income');
        });
    }
};

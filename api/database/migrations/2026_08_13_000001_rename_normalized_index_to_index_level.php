<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `normalized_index` becomes `index_level`.
 *
 * Safe to rename because nothing has ever depended on it: no code wrote it, it
 * was absent from the public API, and every row in every deployment holds null.
 * Its only appearances were three Filament fields displaying a permanently blank
 * value and a factory filling it with 100.0, which is why no test ever noticed
 * it was dead.
 *
 * The new name says what the column now holds: a chain-linked index level, 100
 * at the country's base period, comparable across basket revisions in a way
 * `cost_local` is not (D-23).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('index_snapshots', function (Blueprint $table): void {
            $table->renameColumn('normalized_index', 'index_level');
        });
    }

    public function down(): void
    {
        Schema::table('index_snapshots', function (Blueprint $table): void {
            $table->renameColumn('index_level', 'normalized_index');
        });
    }
};

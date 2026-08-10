<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a snapshot became stale, not merely that it is.
 *
 * Two things need this. The recompute grace window needs to know how long ago
 * an observation landed, so a figure is not republished in the gap between a
 * price being recorded and being screened for anomalies. And an operator needs
 * backlog *age*: a hundred stale snapshots is meaningless, a stale snapshot
 * from six hours ago means recomputation has stopped.
 *
 * Null on existing rows is read as "old enough". Refusing to recompute a
 * snapshot because nobody recorded when it went stale would be the wrong way
 * round: the safe default is to bring it up to date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('index_snapshots', function (Blueprint $table): void {
            $table->timestampTz('stale_marked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('index_snapshots', function (Blueprint $table): void {
            $table->dropColumn('stale_marked_at');
        });
    }
};

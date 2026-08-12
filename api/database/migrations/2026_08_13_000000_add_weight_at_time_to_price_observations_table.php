<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much this observation counted, frozen alongside what we thought of its
 * reporter.
 *
 * Separate from `reputation_at_time` on purpose. Reputation is the posterior
 * mean — a statement about a person, shown in the admin panel and fed to the
 * anomaly detector as a feature. The weight is what the estimator actually did
 * with their price, and it is now the posterior's *lower bound*, which is a
 * different number and a different claim.
 *
 * Frozen rather than joined for the same reason reputation is: recomputing a
 * snapshot from last March must produce March's figure, not a figure reweighted
 * by everything learned since.
 *
 * Null on every row that predates this column, and the estimator falls back to
 * the old behaviour for those. Rewriting history to the new weighting would
 * silently restate published figures that were correct under the rules in force
 * when they were computed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_observations', function (Blueprint $table): void {
            $table->decimal('weight_at_time', 6, 4)->nullable()->after('reputation_at_time');
        });
    }

    public function down(): void
    {
        Schema::table('price_observations', function (Blueprint $table): void {
            $table->dropColumn('weight_at_time');
        });
    }
};

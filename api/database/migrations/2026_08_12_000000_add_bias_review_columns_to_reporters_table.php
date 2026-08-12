<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the manipulation detector thinks of a reporter.
 *
 * Deliberately separate from `is_blocked`, which stays a human decision. The
 * detector's job is to say "look at this one"; deciding that somebody's prices
 * should stop counting is a judgement about a real person doing real work in a
 * difficult place, and a statistical signal is not a sufficient basis for it.
 *
 * The columns record the signal and when it was last measured, so a reporter
 * flagged once and cleared by a human is not re-flagged into invisibility on
 * the next run — an operator can see both the current score and that somebody
 * has already looked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reporters', function (Blueprint $table): void {
            // Modified z-score of this reporter's price ratios against the
            // local median computed without them. Negative means they report
            // consistently below their neighbours.
            $table->decimal('bias_score', 8, 4)->nullable();
            $table->boolean('bias_flagged')->default(false);
            $table->string('bias_reason')->nullable();
            $table->timestampTz('bias_checked_at')->nullable();
            $table->timestampTz('bias_cleared_at')->nullable();

            // The review queue for reporters: flagged, not yet looked at.
            $table->index(['bias_flagged', 'bias_cleared_at']);
        });
    }

    public function down(): void
    {
        Schema::table('reporters', function (Blueprint $table): void {
            $table->dropIndex(['bias_flagged', 'bias_cleared_at']);
            $table->dropColumn([
                'bias_score',
                'bias_flagged',
                'bias_reason',
                'bias_checked_at',
                'bias_cleared_at',
            ]);
        });
    }
};

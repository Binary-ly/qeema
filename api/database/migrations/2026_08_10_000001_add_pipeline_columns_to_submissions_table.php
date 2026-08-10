<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookkeeping for automatic resolution.
 *
 * A submission the pipeline cannot process must not loop forever and must not
 * vanish. These columns are the retry budget and the reason it ran out, so the
 * end of the road is a human looking at the row rather than silence.
 *
 * The budget lives here rather than on the queue message deliberately. Queue
 * attempts reset every time work is re-dispatched — by the sweeper, by an
 * operator, by a worker restart — so a submission failing for a real reason
 * could be retried indefinitely while every individual job looked healthy.
 * Counted on the row, five attempts means five.
 *
 * Nothing here touches the raw submission. `raw_text`, `raw_price` and the rest
 * remain exactly as submitted: this is metadata about processing, not data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('pipeline_attempts')->default(0);
            $table->timestampTz('pipeline_attempted_at')->nullable();
            $table->text('pipeline_last_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->dropColumn(['pipeline_attempts', 'pipeline_attempted_at', 'pipeline_last_error']);
        });
    }
};

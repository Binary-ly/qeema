<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serves the review queue's default ordering.
 *
 * The queue is worked oldest-first, so the page query filters on `status` and
 * sorts on `observed_at`. The existing `(status, created_at)` index serves the
 * filter and not the sort, so Postgres read every matching row and top-N sorted
 * it — 1,137 rows on the demo stack, and linear in the backlog thereafter.
 *
 * Measured on that stack, warm, with the queue's real query:
 *
 *   without: 2.24 ms, bitmap heap scan over all 1,137 matching rows, then sort
 *   with:    0.40 ms, index scan that stops after the first page
 *
 * The absolute numbers are small either way; the plan is the point. One is
 * proportional to the page, the other to the backlog — and a review queue that
 * gets slower the more it has to review is a review queue that stops being
 * used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->index(['status', 'observed_at'], 'submissions_review_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table): void {
            $table->dropIndex('submissions_review_queue_index');
        });
    }
};

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records *why* a submission was routed where it was.
 *
 * A reviewer opening the queue needs to know whether this arrived because the
 * matcher was unsure, because two candidates were indistinguishable, or because
 * the ML service was simply down — those call for completely different
 * responses, and a bare confidence score distinguishes none of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resolutions', function (Blueprint $table): void {
            $table->text('notes')->nullable()
                ->comment('Why this submission was routed as it was; shown to reviewers');
        });
    }

    public function down(): void
    {
        Schema::table('resolutions', function (Blueprint $table): void {
            $table->dropColumn('notes');
        });
    }
};

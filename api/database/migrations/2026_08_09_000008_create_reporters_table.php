<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * People submitting prices, and how much the system trusts them.
 *
 * Reputation is the mean of a Beta posterior rather than a bare accepted/total
 * ratio. That matters at the extremes: a reporter with one accepted submission
 * is not more trustworthy than one with two hundred, and a ratio would say they
 * were. Beta(alpha_0 + accepted, beta_0 + rejected) with alpha_0 = beta_0 = 2
 * starts everyone at 0.5 with wide uncertainty that narrows as evidence arrives.
 *
 * Only human-confirmed verdicts update the posterior. An automated "suspect"
 * flag never does, because otherwise an unlucky new reporter gets down-weighted,
 * deviates further from the down-weighted estimate, and spirals out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporters', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();

            // Anonymous device identity: the PWA generates this and keeps it in
            // local storage. Enough to accrue a reputation without demanding a
            // signup that would suppress participation.
            $table->uuid('external_ref')->unique();
            $table->string('display_name')->nullable();

            $table->decimal('reputation', 5, 4)->default(0.5)
                ->comment('Posterior mean of Beta(alpha, beta)');
            $table->decimal('reputation_alpha', 10, 4)->default(2)
                ->comment('Beta prior + human-confirmed accepted submissions');
            $table->decimal('reputation_beta', 10, 4)->default(2)
                ->comment('Beta prior + human-confirmed rejected submissions');

            $table->unsignedInteger('submissions_total')->default(0);
            $table->unsignedInteger('submissions_accepted')->default(0);
            $table->unsignedInteger('submissions_rejected')->default(0);

            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();

            $table->boolean('is_blocked')->default(false);
            $table->string('blocked_reason')->nullable();

            $table->timestamps();

            $table->index(['country_id', 'is_blocked']);
            $table->index('reputation');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporters');
    }
};

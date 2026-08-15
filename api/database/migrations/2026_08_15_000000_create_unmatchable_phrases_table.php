<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phrases a reviewer has ruled are not a product this deployment tracks.
 *
 * **The half of the review loop that was missing.** Approving a submission calls
 * `learnVariant`, so the phrase that defeated the matcher becomes a catalogue
 * variant and never puzzles it again. Rejecting taught the system nothing at
 * all, so a greeting or a keyboard mash cost a reviewer a decision every single
 * time it arrived. Measured on the scale dataset: `١٢٣٤` was waiting 1,049
 * times, `test 123` 1,047, `تجربه` 1,033, `السلام عليكم` 1,007 — five phrases,
 * five thousand decisions.
 *
 * **This is deliberately not inferred from a plain rejection.** `reject` already
 * means "this submission is unusable", which covers an absurd price, a
 * duplicate, or a test message — and only the last of those says anything about
 * the *phrase*. Learning from the others would be actively destructive: a
 * reviewer rejecting a rice report because the price was nonsense would teach
 * the matcher that أرز matches nothing, and rice would stop resolving for
 * everyone, permanently. So the reviewer states which of the two they mean, and
 * only one of them lands here.
 *
 * Keyed on the normalised form, like variants are, so two spellings that
 * normalise alike are one ruling rather than two. Scoped per country: a phrase
 * that is noise in one deployment may be a product in another.
 *
 * Reversible on purpose. A phrase here silently discards future submissions
 * carrying it, which is a real power — so the row records who created it, from
 * which submission, and why, and deleting the row restores the old behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unmatchable_phrases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->text('text');
            $table->text('normalized_text');
            $table->text('reason')->nullable();

            // Provenance, because this row causes submissions to be discarded.
            $table->foreignUuid('created_from_submission_id')->nullable()->constrained('submissions')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // How often it has been seen since. An operator reviewing these
            // needs to know which rulings are earning their keep and which were
            // a one-off that should probably be deleted.
            $table->unsignedBigInteger('times_matched')->default(0);
            $table->timestamp('last_matched_at')->nullable();
            $table->timestamps();

            $table->unique(['country_id', 'normalized_text']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unmatchable_phrases');
    }
};

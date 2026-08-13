<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What anchors a basket version's index level at one location.
 *
 * The level is `100 × cost_v(L, t) / reference_cost`, so this row is what makes
 * a published level mean anything. For the first basket the reference cost is
 * simply what the basket cost at the country's base date. For every later
 * version it is carried forward from the previous version's anchor by the ratio
 * of the two baskets costed on the same day — the chain link — which is what
 * keeps the series continuous across a revision.
 *
 * Stored rather than derived on read for two reasons. It must be reproducible:
 * a level published last March has to stay the level it was, not drift as late
 * observations arrive and change what the base period looks like. And it must be
 * auditable: `link_factor` and `method` record how the anchor was arrived at,
 * so a step in a chart can be traced to the revision that caused it rather than
 * argued about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('basket_links', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->foreignId('basket_id')->constrained()->cascadeOnDelete();

            // The anchor is per location: a revision does not do the same thing
            // to every location's bundle, so each carries its own factor (D-19).
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();

            // Null for the first version, which is anchored at the base date
            // rather than chained from anything.
            $table->foreignId('previous_basket_id')->nullable()
                ->constrained('baskets')->nullOnDelete();

            // The day both baskets were costed on — or the base date, for a
            // first version.
            $table->date('link_date');

            $table->decimal('reference_cost', 18, 4);

            // cost_new / cost_old on the link date. Null for a first version.
            // Kept for audit: it is the size of the bundle change, and it is the
            // number to look at when a series moves at a revision.
            $table->decimal('link_factor', 12, 6)->nullable();

            $table->string('method', 32)
                ->comment('base_period | chained | chained_country_fallback');

            // What the two baskets cost on the link date. Recorded so an anchor
            // can be checked without recomputing prices that have since moved.
            $table->decimal('previous_cost', 18, 4)->nullable();
            $table->decimal('linked_cost', 18, 4)->nullable();

            $table->timestampTz('computed_at')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            // One anchor per basket per location. The uniqueness is the
            // immutability guarantee: establishing a link twice is a conflict to
            // be resolved deliberately, not an overwrite (D-21).
            $table->unique(['basket_id', 'location_id'], 'basket_links_unique');
            $table->index(['country_id', 'link_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('basket_links');
    }
};

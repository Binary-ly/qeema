<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership of a basket version, with the two numbers that drive everything.
 *
 * `quantity` and `weight` do genuinely different jobs and conflating them is the
 * easiest way to publish a misleading figure:
 *
 *   quantity — how much of this item the basket contains. Drives the cost:
 *              cost = SUM(quantity * unit_price).
 *   weight   — the item's share of the basket's importance. Drives coverage and
 *              the normalised index. A missing 12%-weight infant formula matters
 *              far more than a missing 2%-weight pencil, and a count-based
 *              coverage figure would treat them as equal.
 *
 * Weights are validated to sum to 1.0 across a basket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('basket_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('basket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('canonical_item_id')->constrained()->restrictOnDelete();

            $table->decimal('weight', 8, 6)
                ->comment('Expenditure share; weights sum to 1.0 within a basket');
            $table->decimal('quantity', 12, 4)
                ->comment('How much of this item the basket contains');
            $table->string('unit_code', 24);

            $table->string('category', 64)->index();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['basket_id', 'canonical_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('basket_items');
    }
};

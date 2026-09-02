<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| An absolute anchor for anomaly screening
|--------------------------------------------------------------------------
|
| Every layer of the anomaly detector is *relative*: hard bounds compare a price
| against the item's own trailing median, the robust test compares it against
| other prices for the same item in the same town, and the forest learns from
| the same history. That is deliberate and it is what keeps the thresholds
| country-agnostic — a limit written for one currency is wrong everywhere else
| within months.
|
| The cost is that all three go quiet together. With no observations to compare
| against, `hard_bounds` returns 0.0 by design ("a cold-start item must not have
| everything rejected before any reference exists"), the MAD test has no
| distribution, and the composite is the maximum of three zeros. A submission is
| then recorded `clean` on no evidence at all, and `clean` publishes.
|
| That is not a rare corner. It is the state of every new deployment, every item
| nobody has priced yet, and every deployment whose feeds have gone quiet for
| ninety days. On this project it was reached the ordinary way: a single test
| submission of 10,000 for a kilo of wheat flour scored 0.0000, resolved, and
| went out on the public API — against a catalogue that already records the real
| price as 3.88, with a URL and a date beside it.
|
| Those reference prices existed the whole time and no application code read
| them; they were parsed only by a test. This column is where they land, so that
| screening has something to compare against on the first observation rather
| than on the hundredth.
|
| Nullable, because a country may be configured without sourced prices — and an
| item with no reference must behave exactly as it does today rather than being
| rejected for lack of one.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('canonical_items', function (Blueprint $table): void {
            // Per *base unit*, and named so, because the country file states
            // these per item as sold: a tray of thirty eggs at 24.75, a 60 ml
            // bottle of paracetamol at 8.00. What they are compared against —
            // `price_observations.normalized_price_per_base_unit` — is per egg
            // and per millilitre. The division happens once, at import, where
            // the config's own semantics are in front of the reader, rather
            // than at every call site where forgetting it is a factor-of-thirty
            // error in a screening threshold.
            //
            // Same precision as the column it is compared against.
            $table->decimal('reference_price_per_base_unit', 18, 6)->nullable()->after('pack_size');
        });
    }

    public function down(): void
    {
        Schema::table('canonical_items', function (Blueprint $table): void {
            $table->dropColumn('reference_price_per_base_unit');
        });
    }
};

<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Two facts the matcher needs and the schema did not carry
|--------------------------------------------------------------------------
|
| The largest single class of matching error was two items that share a head
| noun and differ only by size — a 50kg trade sack against a 1kg household bag,
| priced roughly sixty times apart. Reporters routinely state the size, so the
| evidence is there in the text; what was missing was anything to compare it to.
|
| `units.aliases` is how a size gets read at all. The unit table stored one
| formal name per unit, and nobody writes the formal name: they write the short
| form, or glue it to the digits. Aliases live per country because the words do.
|
| `canonical_items.pack_size` is what the size gets compared against. Seven
| items declare `1 pack` while their own code states a real quantity — a 400g
| tin is stored as one pack — so the size lived in the code string and not in
| the data. Measured against `default_quantity` the size signal picked the right
| item 33 times out of 52; against a real pack size, 45 out of 45.
|
| Deliberately a separate column rather than a correction to `default_quantity`:
| that one multiplies through basket costing, and the last time a quantity moved
| without its unit the published figure came out a thousand times too high.
|
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table): void {
            $table->jsonb('aliases')->nullable()->after('name_local');
        });

        Schema::table('canonical_items', function (Blueprint $table): void {
            $table->jsonb('pack_size')->nullable()->after('default_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('units', function (Blueprint $table): void {
            $table->dropColumn('aliases');
        });

        Schema::table('canonical_items', function (Blueprint $table): void {
            $table->dropColumn('pack_size');
        });
    }
};

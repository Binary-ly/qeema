<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Countries are the root of the configuration tree.
 *
 * Constraint C3 requires the platform to be country-agnostic, so every fact that
 * differs between deployments — currency, locales, timezone, what the
 * administrative levels are even called, where FX rates come from — is a column
 * here rather than a constant in code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table): void {
            $table->id();

            $table->string('code', 2)->unique()->comment('ISO 3166-1 alpha-2');
            $table->string('name');
            $table->string('name_local')->nullable()
                ->comment('Endonym, shown in the local-language UI');

            $table->string('currency_code', 3)->comment('ISO 4217');
            $table->string('currency_symbol', 8)->nullable();
            $table->unsignedTinyInteger('currency_minor_units')->default(2)
                ->comment('Decimal places; not every currency uses 2');

            $table->string('default_locale', 12)->default('en');
            $table->jsonb('locales')->default(new Expression("'[\"en\"]'::jsonb"))
                ->comment('Locales offered in the reporter PWA and dashboard');
            $table->string('timezone', 64)->default('UTC');

            // Administrative divisions are named differently everywhere
            // (municipality/district, governorate/directorate, state/locality).
            // Storing the labels keeps the UI honest without special-casing.
            $table->string('admin1_label')->default('Region');
            $table->string('admin2_label')->nullable();

            $table->jsonb('fx_config')->nullable()
                ->comment('Rate provider selection and its settings');
            $table->jsonb('index_config')->nullable()
                ->comment('Observation window, half-life, bootstrap draws, base date');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('countries');
    }
};

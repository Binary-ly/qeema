<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\Countries\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CountryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('name_local'),
                TextInput::make('currency_code')
                    ->required(),
                TextInput::make('currency_symbol'),
                TextInput::make('currency_minor_units')
                    ->required()
                    ->numeric()
                    ->default(2),
                TextInput::make('default_locale')
                    ->required()
                    ->default('en'),
                TextInput::make('locales')
                    ->required()
                    ->default('["en"]'),
                TextInput::make('timezone')
                    ->required()
                    ->default('UTC'),
                TextInput::make('admin1_label')
                    ->required()
                    ->default('Region'),
                TextInput::make('admin2_label'),
                TextInput::make('fx_config'),
                TextInput::make('index_config'),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\Units\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('country_id')
                    ->relationship('country', 'name'),
                TextInput::make('code')
                    ->required(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('name_local'),
                TextInput::make('dimension')
                    ->required(),
                TextInput::make('base_unit_code')
                    ->required(),
                TextInput::make('factor_to_base')
                    ->required()
                    ->numeric(),
            ]);
    }
}

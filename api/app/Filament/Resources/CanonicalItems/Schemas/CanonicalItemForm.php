<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\CanonicalItems\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CanonicalItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('country_id')
                    ->relationship('country', 'name')
                    ->required(),
                TextInput::make('code')
                    ->required(),
                TextInput::make('name_en')
                    ->required(),
                TextInput::make('name_local'),
                TextInput::make('category')
                    ->required(),
                TextInput::make('default_unit_code')
                    ->required(),
                TextInput::make('default_quantity')
                    ->required()
                    ->numeric()
                    ->default(1),
                TextInput::make('embedding'),
                TextInput::make('embedding_model'),
                DateTimePicker::make('embedding_updated_at'),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}

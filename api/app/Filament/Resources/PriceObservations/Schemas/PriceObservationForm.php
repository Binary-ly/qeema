<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\PriceObservations\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PriceObservationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('submission_id')
                    ->relationship('submission', 'id')
                    ->required(),
                TextInput::make('country_id')
                    ->required()
                    ->numeric(),
                Select::make('location_id')
                    ->relationship('location', 'name')
                    ->required(),
                Select::make('canonical_item_id')
                    ->relationship('canonicalItem', 'id')
                    ->required(),
                TextInput::make('price')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('currency_code')
                    ->required(),
                TextInput::make('unit_code')
                    ->required(),
                TextInput::make('quantity')
                    ->required()
                    ->numeric(),
                TextInput::make('normalized_price_per_base_unit')
                    ->required()
                    ->numeric(),
                DatePicker::make('observed_on')
                    ->required(),
                DateTimePicker::make('observed_at')
                    ->required(),
                Select::make('reporter_id')
                    ->relationship('reporter', 'id'),
                TextInput::make('source_id')
                    ->required()
                    ->numeric(),
                TextInput::make('reputation_at_time')
                    ->required()
                    ->numeric()
                    ->default(0.5),
                Toggle::make('is_valid')
                    ->required(),
                Select::make('superseded_by_id')
                    ->relationship('supersededBy', 'id'),
            ]);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\FxRates\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FxRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('country_id')
                    ->relationship('country', 'name')
                    ->required(),
                DatePicker::make('rate_date')
                    ->required(),
                TextInput::make('official_rate')
                    ->numeric(),
                TextInput::make('parallel_rate')
                    ->numeric(),
                TextInput::make('base_currency')
                    ->required()
                    ->default('USD'),
                TextInput::make('source')
                    ->required(),
                Toggle::make('is_manual')
                    ->required(),
                TextInput::make('raw'),
                DateTimePicker::make('fetched_at'),
            ]);
    }
}

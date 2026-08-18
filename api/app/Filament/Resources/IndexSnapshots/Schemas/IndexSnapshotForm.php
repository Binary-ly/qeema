<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\IndexSnapshots\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class IndexSnapshotForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('country_id')
                    ->relationship('country', 'name')
                    ->required(),
                Select::make('location_id')
                    ->relationship('location', 'name')
                    ->required(),
                Select::make('basket_id')
                    ->relationship('basket', 'name')
                    ->required(),
                DatePicker::make('snapshot_date')
                    ->required(),
                TextInput::make('cost_local')
                    ->required()
                    ->numeric(),
                TextInput::make('cost_usd')
                    ->numeric(),
                TextInput::make('index_level')
                    ->numeric(),
                TextInput::make('coverage_pct')
                    ->required()
                    ->numeric(),
                TextInput::make('imputed_share')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('ci_low_local')
                    ->numeric(),
                TextInput::make('ci_high_local')
                    ->numeric(),
                TextInput::make('fx_rate_used')
                    ->numeric(),
                TextInput::make('fx_rate_type'),
                DatePicker::make('fx_rate_date'),
                Toggle::make('fx_is_stale')
                    ->required(),
                TextInput::make('observed_item_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('total_item_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                Toggle::make('is_stale')
                    ->required(),
                DateTimePicker::make('computed_at'),
                TextInput::make('model_version'),
            ]);
    }
}

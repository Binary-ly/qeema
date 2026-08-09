<?php

namespace App\Filament\Resources\IndexSnapshotItems\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class IndexSnapshotItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('index_snapshot_id')
                    ->relationship('indexSnapshot', 'id')
                    ->required(),
                Select::make('canonical_item_id')
                    ->relationship('canonicalItem', 'id')
                    ->required(),
                TextInput::make('unit_price_local')
                    ->required()
                    ->numeric(),
                TextInput::make('weight')
                    ->required()
                    ->numeric(),
                TextInput::make('quantity')
                    ->required()
                    ->numeric(),
                TextInput::make('contribution_local')
                    ->required()
                    ->numeric(),
                Toggle::make('is_imputed')
                    ->required(),
                TextInput::make('imputation_method'),
                TextInput::make('ci_low')
                    ->numeric(),
                TextInput::make('ci_high')
                    ->numeric(),
                TextInput::make('observation_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('source_observation_ids'),
            ]);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\BasketItems\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class BasketItemForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('basket_id')
                    ->relationship('basket', 'name')
                    ->required(),
                Select::make('canonical_item_id')
                    ->relationship('canonicalItem', 'id')
                    ->required(),
                TextInput::make('weight')
                    ->required()
                    ->numeric(),
                TextInput::make('quantity')
                    ->required()
                    ->numeric(),
                TextInput::make('unit_code')
                    ->required(),
                TextInput::make('category')
                    ->required(),
                Textarea::make('notes')
                    ->columnSpanFull(),
            ]);
    }
}

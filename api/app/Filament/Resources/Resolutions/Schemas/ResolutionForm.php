<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\Resolutions\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ResolutionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('submission_id')
                    ->relationship('submission', 'id')
                    ->required(),
                Select::make('canonical_item_id')
                    ->relationship('canonicalItem', 'id'),
                TextInput::make('method')
                    ->required(),
                TextInput::make('confidence')
                    ->numeric(),
                TextInput::make('candidates'),
                Toggle::make('reviewed')
                    ->required(),
                TextInput::make('reviewed_by_user_id')
                    ->numeric(),
                DateTimePicker::make('reviewed_at'),
                TextInput::make('model_version'),
            ]);
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\CanonicalItemVariants\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CanonicalItemVariantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('canonical_item_id')
                    ->relationship('canonicalItem', 'id')
                    ->required(),
                Textarea::make('text')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('normalized_text')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('locale'),
                TextInput::make('source')
                    ->required()
                    ->default('seed'),
                Select::make('created_from_submission_id')
                    ->relationship('createdFromSubmission', 'id'),
                TextInput::make('created_by_user_id')
                    ->numeric(),
                TextInput::make('times_matched')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}

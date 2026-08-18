<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\IngestionBatches\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class IngestionBatchForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('source_id')
                    ->relationship('source', 'name')
                    ->required(),
                TextInput::make('uploaded_by_user_id')
                    ->numeric(),
                TextInput::make('filename'),
                TextInput::make('checksum'),
                TextInput::make('row_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('accepted_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('rejected_count')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('status')
                    ->required()
                    ->default('pending'),
                TextInput::make('column_mapping'),
                TextInput::make('error_report'),
                DateTimePicker::make('started_at'),
                DateTimePicker::make('finished_at'),
            ]);
    }
}

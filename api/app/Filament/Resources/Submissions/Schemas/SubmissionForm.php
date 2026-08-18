<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\Submissions\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class SubmissionForm
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
                Select::make('reporter_id')
                    ->relationship('reporter', 'id'),
                Select::make('source_id')
                    ->relationship('source', 'name')
                    ->required(),
                Select::make('ingestion_batch_id')
                    ->relationship('ingestionBatch', 'id'),
                Textarea::make('raw_text')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('raw_price')
                    ->required()
                    ->numeric()
                    ->prefix('$'),
                TextInput::make('currency_code')
                    ->required(),
                TextInput::make('raw_unit'),
                TextInput::make('raw_quantity')
                    ->numeric(),
                TextInput::make('photo_path'),
                DateTimePicker::make('observed_at')
                    ->required(),
                DateTimePicker::make('collected_at')
                    ->required(),
                DateTimePicker::make('ingested_at')
                    ->required(),
                TextInput::make('device_metadata'),
                TextInput::make('client_idempotency_key'),
                TextInput::make('status')
                    ->required()
                    ->default('pending'),
            ]);
    }
}

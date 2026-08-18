<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\Reporters\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ReporterForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('country_id')
                    ->relationship('country', 'name')
                    ->required(),
                Select::make('location_id')
                    ->relationship('location', 'name'),
                TextInput::make('external_ref')
                    ->required(),
                TextInput::make('display_name'),
                TextInput::make('reputation')
                    ->required()
                    ->numeric()
                    ->default(0.5),
                TextInput::make('reputation_alpha')
                    ->required()
                    ->numeric()
                    ->default(2),
                TextInput::make('reputation_beta')
                    ->required()
                    ->numeric()
                    ->default(2),
                TextInput::make('submissions_total')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('submissions_accepted')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('submissions_rejected')
                    ->required()
                    ->numeric()
                    ->default(0),
                DateTimePicker::make('first_seen_at'),
                DateTimePicker::make('last_seen_at'),
                Toggle::make('is_blocked')
                    ->required(),
                TextInput::make('blocked_reason'),
            ]);
    }
}

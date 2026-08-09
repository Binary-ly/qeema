<?php

namespace App\Filament\Resources\AnomalyScores\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AnomalyScoreForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('submission_id')
                    ->relationship('submission', 'id')
                    ->required(),
                TextInput::make('score')
                    ->required()
                    ->numeric(),
                TextInput::make('verdict')
                    ->required(),
                TextInput::make('reasons'),
                TextInput::make('layer_scores'),
                TextInput::make('model_version'),
            ]);
    }
}

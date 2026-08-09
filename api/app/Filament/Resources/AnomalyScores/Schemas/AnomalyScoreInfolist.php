<?php

namespace App\Filament\Resources\AnomalyScores\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class AnomalyScoreInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('submission.id')
                    ->label('Submission'),
                TextEntry::make('score')
                    ->numeric(),
                TextEntry::make('verdict'),
                TextEntry::make('model_version')
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime(),
            ]);
    }
}

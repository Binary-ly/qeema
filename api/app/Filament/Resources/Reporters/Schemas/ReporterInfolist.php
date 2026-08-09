<?php

namespace App\Filament\Resources\Reporters\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class ReporterInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('country.name')
                    ->label('Country'),
                TextEntry::make('location.name')
                    ->label('Location')
                    ->placeholder('-'),
                TextEntry::make('external_ref'),
                TextEntry::make('display_name')
                    ->placeholder('-'),
                TextEntry::make('reputation')
                    ->numeric(),
                TextEntry::make('reputation_alpha')
                    ->numeric(),
                TextEntry::make('reputation_beta')
                    ->numeric(),
                TextEntry::make('submissions_total')
                    ->numeric(),
                TextEntry::make('submissions_accepted')
                    ->numeric(),
                TextEntry::make('submissions_rejected')
                    ->numeric(),
                TextEntry::make('first_seen_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('last_seen_at')
                    ->dateTime()
                    ->placeholder('-'),
                IconEntry::make('is_blocked')
                    ->boolean(),
                TextEntry::make('blocked_reason')
                    ->placeholder('-'),
                TextEntry::make('created_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('updated_at')
                    ->dateTime()
                    ->placeholder('-'),
            ]);
    }
}

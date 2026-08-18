<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\IngestionBatches\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class IngestionBatchInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('source.name')
                    ->label('Source'),
                TextEntry::make('uploaded_by_user_id')
                    ->numeric()
                    ->placeholder('-'),
                TextEntry::make('filename')
                    ->placeholder('-'),
                TextEntry::make('checksum')
                    ->placeholder('-'),
                TextEntry::make('row_count')
                    ->numeric(),
                TextEntry::make('accepted_count')
                    ->numeric(),
                TextEntry::make('rejected_count')
                    ->numeric(),
                TextEntry::make('status'),
                TextEntry::make('started_at')
                    ->dateTime()
                    ->placeholder('-'),
                TextEntry::make('finished_at')
                    ->dateTime()
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

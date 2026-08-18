<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\IngestionBatches\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IngestionBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('source.name')
                    ->searchable(),
                TextColumn::make('uploaded_by_user_id')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('filename')
                    ->searchable(),
                TextColumn::make('checksum')
                    ->searchable(),
                TextColumn::make('row_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('accepted_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('rejected_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('status')
                    ->searchable(),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('finished_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

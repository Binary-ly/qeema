<?php

namespace App\Filament\Resources\Submissions\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SubmissionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID'),
                TextColumn::make('country.name')
                    ->searchable(),
                TextColumn::make('location.name')
                    ->searchable(),
                TextColumn::make('reporter.id')
                    ->searchable(),
                TextColumn::make('source.name')
                    ->searchable(),
                TextColumn::make('ingestionBatch.id')
                    ->searchable(),
                TextColumn::make('raw_price')
                    ->money()
                    ->sortable(),
                TextColumn::make('currency_code')
                    ->searchable(),
                TextColumn::make('raw_unit')
                    ->searchable(),
                TextColumn::make('raw_quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('photo_path')
                    ->searchable(),
                TextColumn::make('observed_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('collected_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('ingested_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('client_idempotency_key'),
                TextColumn::make('status')
                    ->searchable(),
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

<?php

namespace App\Filament\Resources\IndexSnapshotItems\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IndexSnapshotItemsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('indexSnapshot.id')
                    ->searchable(),
                TextColumn::make('canonicalItem.id')
                    ->searchable(),
                TextColumn::make('unit_price_local')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('weight')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('quantity')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('contribution_local')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_imputed')
                    ->boolean(),
                TextColumn::make('imputation_method')
                    ->searchable(),
                TextColumn::make('ci_low')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('ci_high')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('observation_count')
                    ->numeric()
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

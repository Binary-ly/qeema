<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\FxRates\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FxRatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('country.name')
                    ->searchable(),
                TextColumn::make('rate_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('official_rate')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('parallel_rate')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('base_currency')
                    ->searchable(),
                TextColumn::make('source')
                    ->searchable(),
                IconColumn::make('is_manual')
                    ->boolean(),
                TextColumn::make('fetched_at')
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

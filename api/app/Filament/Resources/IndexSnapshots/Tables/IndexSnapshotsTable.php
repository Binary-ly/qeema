<?php

namespace App\Filament\Resources\IndexSnapshots\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IndexSnapshotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('country.name')
                    ->searchable(),
                TextColumn::make('location.name')
                    ->searchable(),
                TextColumn::make('basket.name')
                    ->searchable(),
                TextColumn::make('snapshot_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('cost_local')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('cost_usd')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('normalized_index')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('coverage_pct')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('imputed_share')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('ci_low_local')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('ci_high_local')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('fx_rate_used')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('fx_rate_type')
                    ->searchable(),
                TextColumn::make('fx_rate_date')
                    ->date()
                    ->sortable(),
                IconColumn::make('fx_is_stale')
                    ->boolean(),
                TextColumn::make('observed_item_count')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('total_item_count')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_stale')
                    ->boolean(),
                TextColumn::make('computed_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('model_version')
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

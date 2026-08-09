<?php

namespace App\Filament\Resources\Reporters\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReportersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('country.name')
                    ->searchable(),
                TextColumn::make('location.name')
                    ->searchable(),
                TextColumn::make('external_ref'),
                TextColumn::make('display_name')
                    ->searchable(),
                TextColumn::make('reputation')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reputation_alpha')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('reputation_beta')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('submissions_total')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('submissions_accepted')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('submissions_rejected')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('first_seen_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->dateTime()
                    ->sortable(),
                IconColumn::make('is_blocked')
                    ->boolean(),
                TextColumn::make('blocked_reason')
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

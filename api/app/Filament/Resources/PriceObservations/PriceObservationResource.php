<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\PriceObservations;

use App\Filament\Resources\PriceObservations\Pages\CreatePriceObservation;
use App\Filament\Resources\PriceObservations\Pages\EditPriceObservation;
use App\Filament\Resources\PriceObservations\Pages\ListPriceObservations;
use App\Filament\Resources\PriceObservations\Pages\ViewPriceObservation;
use App\Filament\Resources\PriceObservations\Schemas\PriceObservationForm;
use App\Filament\Resources\PriceObservations\Schemas\PriceObservationInfolist;
use App\Filament\Resources\PriceObservations\Tables\PriceObservationsTable;
use App\Models\PriceObservation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PriceObservationResource extends Resource
{
    protected static ?string $model = PriceObservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PriceObservationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PriceObservationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PriceObservationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPriceObservations::route('/'),
            'create' => CreatePriceObservation::route('/create'),
            'view' => ViewPriceObservation::route('/{record}'),
            'edit' => EditPriceObservation::route('/{record}/edit'),
        ];
    }
}

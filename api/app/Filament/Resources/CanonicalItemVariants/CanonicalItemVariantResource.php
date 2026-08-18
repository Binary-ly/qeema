<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\CanonicalItemVariants;

use App\Filament\Resources\CanonicalItemVariants\Pages\CreateCanonicalItemVariant;
use App\Filament\Resources\CanonicalItemVariants\Pages\EditCanonicalItemVariant;
use App\Filament\Resources\CanonicalItemVariants\Pages\ListCanonicalItemVariants;
use App\Filament\Resources\CanonicalItemVariants\Pages\ViewCanonicalItemVariant;
use App\Filament\Resources\CanonicalItemVariants\Schemas\CanonicalItemVariantForm;
use App\Filament\Resources\CanonicalItemVariants\Schemas\CanonicalItemVariantInfolist;
use App\Filament\Resources\CanonicalItemVariants\Tables\CanonicalItemVariantsTable;
use App\Models\CanonicalItemVariant;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class CanonicalItemVariantResource extends Resource
{
    protected static ?string $model = CanonicalItemVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return CanonicalItemVariantForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CanonicalItemVariantInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CanonicalItemVariantsTable::configure($table);
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
            'index' => ListCanonicalItemVariants::route('/'),
            'create' => CreateCanonicalItemVariant::route('/create'),
            'view' => ViewCanonicalItemVariant::route('/{record}'),
            'edit' => EditCanonicalItemVariant::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources\CanonicalItemVariants\Pages;

use App\Filament\Resources\CanonicalItemVariants\CanonicalItemVariantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCanonicalItemVariants extends ListRecords
{
    protected static string $resource = CanonicalItemVariantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

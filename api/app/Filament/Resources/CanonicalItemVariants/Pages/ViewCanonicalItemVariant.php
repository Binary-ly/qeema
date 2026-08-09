<?php

namespace App\Filament\Resources\CanonicalItemVariants\Pages;

use App\Filament\Resources\CanonicalItemVariants\CanonicalItemVariantResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCanonicalItemVariant extends ViewRecord
{
    protected static string $resource = CanonicalItemVariantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

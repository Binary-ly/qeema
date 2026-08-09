<?php

namespace App\Filament\Resources\CanonicalItemVariants\Pages;

use App\Filament\Resources\CanonicalItemVariants\CanonicalItemVariantResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCanonicalItemVariant extends EditRecord
{
    protected static string $resource = CanonicalItemVariantResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

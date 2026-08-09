<?php

namespace App\Filament\Resources\CanonicalItems\Pages;

use App\Filament\Resources\CanonicalItems\CanonicalItemResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCanonicalItem extends EditRecord
{
    protected static string $resource = CanonicalItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

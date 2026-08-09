<?php

namespace App\Filament\Resources\Baskets\Pages;

use App\Filament\Resources\Baskets\BasketResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditBasket extends EditRecord
{
    protected static string $resource = BasketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

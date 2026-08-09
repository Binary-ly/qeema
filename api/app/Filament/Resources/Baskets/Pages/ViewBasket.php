<?php

namespace App\Filament\Resources\Baskets\Pages;

use App\Filament\Resources\Baskets\BasketResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBasket extends ViewRecord
{
    protected static string $resource = BasketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

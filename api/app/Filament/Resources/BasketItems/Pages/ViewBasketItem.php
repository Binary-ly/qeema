<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\BasketItems\Pages;

use App\Filament\Resources\BasketItems\BasketItemResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBasketItem extends ViewRecord
{
    protected static string $resource = BasketItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\BasketItems\Pages;

use App\Filament\Resources\BasketItems\BasketItemResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditBasketItem extends EditRecord
{
    protected static string $resource = BasketItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

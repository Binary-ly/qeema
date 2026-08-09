<?php

namespace App\Filament\Resources\BasketItems\Pages;

use App\Filament\Resources\BasketItems\BasketItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBasketItems extends ListRecords
{
    protected static string $resource = BasketItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

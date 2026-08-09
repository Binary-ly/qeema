<?php

namespace App\Filament\Resources\Baskets\Pages;

use App\Filament\Resources\Baskets\BasketResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBaskets extends ListRecords
{
    protected static string $resource = BasketResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

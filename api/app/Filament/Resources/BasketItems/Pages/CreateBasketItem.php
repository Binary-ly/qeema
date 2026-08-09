<?php

namespace App\Filament\Resources\BasketItems\Pages;

use App\Filament\Resources\BasketItems\BasketItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBasketItem extends CreateRecord
{
    protected static string $resource = BasketItemResource::class;
}

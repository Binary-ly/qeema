<?php

namespace App\Filament\Resources\CanonicalItems\Pages;

use App\Filament\Resources\CanonicalItems\CanonicalItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCanonicalItems extends ListRecords
{
    protected static string $resource = CanonicalItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

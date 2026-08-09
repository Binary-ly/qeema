<?php

namespace App\Filament\Resources\IndexSnapshotItems\Pages;

use App\Filament\Resources\IndexSnapshotItems\IndexSnapshotItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIndexSnapshotItems extends ListRecords
{
    protected static string $resource = IndexSnapshotItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

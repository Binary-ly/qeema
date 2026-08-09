<?php

namespace App\Filament\Resources\IndexSnapshots\Pages;

use App\Filament\Resources\IndexSnapshots\IndexSnapshotResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIndexSnapshots extends ListRecords
{
    protected static string $resource = IndexSnapshotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

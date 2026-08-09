<?php

namespace App\Filament\Resources\IngestionBatches\Pages;

use App\Filament\Resources\IngestionBatches\IngestionBatchResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListIngestionBatches extends ListRecords
{
    protected static string $resource = IngestionBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

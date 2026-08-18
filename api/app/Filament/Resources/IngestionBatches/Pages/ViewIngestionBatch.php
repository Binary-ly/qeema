<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\IngestionBatches\Pages;

use App\Filament\Resources\IngestionBatches\IngestionBatchResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewIngestionBatch extends ViewRecord
{
    protected static string $resource = IngestionBatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

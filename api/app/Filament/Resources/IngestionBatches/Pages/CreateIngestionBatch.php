<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\IngestionBatches\Pages;

use App\Filament\Resources\IngestionBatches\IngestionBatchResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIngestionBatch extends CreateRecord
{
    protected static string $resource = IngestionBatchResource::class;
}

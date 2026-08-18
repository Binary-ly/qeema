<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\IndexSnapshots\Pages;

use App\Filament\Resources\IndexSnapshots\IndexSnapshotResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewIndexSnapshot extends ViewRecord
{
    protected static string $resource = IndexSnapshotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

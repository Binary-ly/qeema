<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\IndexSnapshots\Pages;

use App\Filament\Resources\IndexSnapshots\IndexSnapshotResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditIndexSnapshot extends EditRecord
{
    protected static string $resource = IndexSnapshotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}

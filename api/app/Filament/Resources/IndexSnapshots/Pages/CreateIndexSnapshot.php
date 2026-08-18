<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\IndexSnapshots\Pages;

use App\Filament\Resources\IndexSnapshots\IndexSnapshotResource;
use Filament\Resources\Pages\CreateRecord;

class CreateIndexSnapshot extends CreateRecord
{
    protected static string $resource = IndexSnapshotResource::class;
}

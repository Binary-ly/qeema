<?php

// SPDX-License-Identifier: Apache-2.0

namespace App\Filament\Resources\CanonicalItems\Pages;

use App\Filament\Resources\CanonicalItems\CanonicalItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCanonicalItem extends CreateRecord
{
    protected static string $resource = CanonicalItemResource::class;
}

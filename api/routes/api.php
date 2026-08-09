<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API (v1)
|--------------------------------------------------------------------------
|
| The published data is the product: every read route here is deliberately
| unauthenticated (constraint C6). Do not add auth middleware to this group.
| Rate limiting is applied per IP instead — see AppServiceProvider.
|
*/

Route::prefix('v1')->name('api.v1.')->middleware('throttle:public-api')->group(function (): void {
    Route::get('/health', [HealthController::class, 'show'])->name('health');
});

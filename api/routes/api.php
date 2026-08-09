<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\SubmissionController;
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

Route::prefix('v1')->name('api.v1.')->group(function (): void {

    Route::middleware('throttle:public-api')->group(function (): void {
        Route::get('/health', [HealthController::class, 'show'])->name('health');

        // Everything the reporter app needs to function offline, in one call.
        Route::get('/bootstrap/{countryCode}', [SubmissionController::class, 'bootstrap'])
            ->name('bootstrap');
    });

    /*
    | The only write route on the public API. Rate limited far more tightly than
    | reads: a reporter submits a handful of prices at a time, and a burst well
    | beyond that is either a bug in a retry loop or an attempt to move the
    | index. Legitimate offline queue flushes stay comfortably inside it.
    */
    Route::middleware('throttle:submissions')->group(function (): void {
        Route::post('/submissions', [SubmissionController::class, 'store'])->name('submissions.store');
    });
});

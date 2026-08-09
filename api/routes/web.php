<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReporterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
*/

// The reporter PWA. Public: anyone willing to report a price may do so, and
// requiring a signup would suppress exactly the participation this depends on.
Route::get('/report', ReporterController::class)->name('reporter');
Route::view('/offline', 'reporter-offline')->name('reporter.offline');

// The published contract, served from disk so it is available offline and
// needs no runtime generation.
Route::get('/api/v1/openapi.json', function () {
    $path = public_path('openapi.json');

    abort_unless(is_file($path), 404, 'Specification has not been generated.');

    return response()->file($path, [
        'Content-Type' => 'application/json',
        'Cache-Control' => 'public, max-age=300',
    ]);
})->name('openapi');

// Documentation. Rendered from the spec with no external assets — constraint C1
// rules out a CDN-hosted renderer, and a docs page that needs the network is
// useless in exactly the deployments this platform targets.
Route::view('/docs', 'docs')->name('docs');

// The public dashboard is the front page: the published data is the product.
Route::get('/', DashboardController::class)->name('dashboard');

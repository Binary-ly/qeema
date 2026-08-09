<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

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

Route::redirect('/', '/report');

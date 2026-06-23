<?php

use Illuminate\Support\Facades\Route;
use Spdotdev\Inventory\Http\Controllers\Api\HealthController;

// Headless API for the Android client. Versioned from day one; host-based
// routed on the configured inventory domain. Resources land here in later steps
// (auth, households, locations/shelves/products) per the API contract.
Route::domain(config('inventory.domain'))
    ->prefix('api/v1')
    ->middleware('api')
    ->group(function () {
        Route::get('/health', HealthController::class)->name('inventory.api.health');
    });

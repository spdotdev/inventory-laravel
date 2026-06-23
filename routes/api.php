<?php

use Illuminate\Support\Facades\Route;
use Spdotdev\Inventory\Http\Controllers\Api\AuthController;
use Spdotdev\Inventory\Http\Controllers\Api\HealthController;

// Headless API for the Android client. Versioned from day one; host-based
// routed on the configured inventory domain. Resources land here in later steps
// (households, locations/shelves/products) per the API contract.
Route::domain(config('inventory.domain'))
    ->prefix('api/v1')
    ->middleware('api')
    ->group(function () {
        Route::get('/health', HealthController::class)->name('inventory.api.health');

        Route::prefix('auth')->group(function () {
            Route::post('register', [AuthController::class, 'register'])->name('inventory.api.auth.register');
            Route::post('login', [AuthController::class, 'login'])->name('inventory.api.auth.login');
            Route::post('google', [AuthController::class, 'google'])->name('inventory.api.auth.google');
            Route::post('logout', [AuthController::class, 'logout'])
                ->middleware('auth:sanctum')
                ->name('inventory.api.auth.logout');
        });
    });

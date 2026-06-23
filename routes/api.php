<?php

use Illuminate\Support\Facades\Route;
use Spdotdev\Inventory\Http\Controllers\Api\AuthController;
use Spdotdev\Inventory\Http\Controllers\Api\HealthController;
use Spdotdev\Inventory\Http\Controllers\Api\HouseholdController;
use Spdotdev\Inventory\Http\Controllers\Api\SearchController;

// Headless API for the Android client. Versioned from day one; host-based
// routed on the configured inventory domain. Locations/shelves/products CRUD
// land here in the next step per the API contract.
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

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('households', [HouseholdController::class, 'index'])->name('inventory.api.households.index');
            Route::post('households', [HouseholdController::class, 'store'])->name('inventory.api.households.store');
            // Defined before the {household} routes so "join" isn't captured as an id.
            Route::post('households/join', [HouseholdController::class, 'join'])->name('inventory.api.households.join');

            Route::middleware('household.member')->group(function () {
                Route::get('households/{household}/invite', [HouseholdController::class, 'invite'])->name('inventory.api.households.invite');
                Route::delete('households/{household}/leave', [HouseholdController::class, 'leave'])->name('inventory.api.households.leave');
                Route::get('households/{household}/search', SearchController::class)->name('inventory.api.households.search');
            });
        });
    });

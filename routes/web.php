<?php

use Illuminate\Support\Facades\Route;
use Spdotdev\Inventory\Http\Controllers\JoinController;
use Spdotdev\Inventory\Http\Controllers\LandingController;
use Spdotdev\Inventory\Http\Controllers\ResetPasswordController;
use Spdotdev\Inventory\Http\Controllers\Web\WebAuthController;
use Spdotdev\Inventory\Http\Controllers\Web\WebHouseholdController;

// Marketing landing page + password reset form, served on the configured inventory host.
Route::domain(config('inventory.domain'))
    ->middleware('web')
    ->group(function () {
        Route::get('/', [LandingController::class, 'index'])->name('inventory.landing');
        Route::get('/reset-password', [ResetPasswordController::class, 'show'])->name('inventory.reset-password');
        Route::post('/reset-password', [ResetPasswordController::class, 'update'])->name('inventory.reset-password.update');
        Route::get('/join/{code}', [JoinController::class, 'show'])->name('inventory.join');

        // Phase-2 web UI: session auth on the `inventory` guard (inventory_users).
        Route::middleware('throttle:inventory-auth')->group(function () {
            Route::post('/login', [WebAuthController::class, 'login'])->name('inventory.web.login');
            Route::post('/register', [WebAuthController::class, 'register'])->name('inventory.web.register');
        });
        Route::get('/login', [WebAuthController::class, 'showLogin'])->name('inventory.web.login.show');
        Route::get('/register', [WebAuthController::class, 'showRegister'])->name('inventory.web.register.show');

        Route::middleware('auth:inventory')->prefix('app')->group(function () {
            Route::post('/logout', [WebAuthController::class, 'logout'])->name('inventory.web.logout');
            Route::get('/households', [WebHouseholdController::class, 'index'])->name('inventory.web.households');
            Route::post('/households', [WebHouseholdController::class, 'store'])->name('inventory.web.households.store');
            Route::post('/households/join', [WebHouseholdController::class, 'join'])->name('inventory.web.households.join');
            Route::get('/households/{household}', [WebHouseholdController::class, 'show'])->name('inventory.web.households.show');
            Route::delete('/households/{household}/leave', [WebHouseholdController::class, 'leave'])->name('inventory.web.households.leave');
        });
    });

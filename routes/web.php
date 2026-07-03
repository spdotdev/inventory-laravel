<?php

use Illuminate\Support\Facades\Route;
use Spdotdev\Inventory\Http\Controllers\LandingController;
use Spdotdev\Inventory\Http\Controllers\ResetPasswordController;

// Marketing landing page + password reset form, served on the configured inventory host.
Route::domain(config('inventory.domain'))
    ->middleware('web')
    ->group(function () {
        Route::get('/', [LandingController::class, 'index'])->name('inventory.landing');
        Route::get('/reset-password', [ResetPasswordController::class, 'show'])->name('inventory.reset-password');
        Route::post('/reset-password', [ResetPasswordController::class, 'update'])->name('inventory.reset-password.update');
    });

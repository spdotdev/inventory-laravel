<?php

use Illuminate\Support\Facades\Route;
use Spdotdev\Inventory\Http\Controllers\LandingController;

// Marketing landing page, served at the root of the configured inventory host.
Route::domain(config('inventory.domain'))
    ->middleware('web')
    ->group(function () {
        Route::get('/', [LandingController::class, 'index'])->name('inventory.landing');
    });

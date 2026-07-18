<?php

use Illuminate\Support\Facades\Route;
use Spdotdev\Inventory\Http\Controllers\JoinController;
use Spdotdev\Inventory\Http\Controllers\LandingController;
use Spdotdev\Inventory\Http\Controllers\ResetPasswordController;
use Spdotdev\Inventory\Http\Controllers\Web\WebAuthController;
use Spdotdev\Inventory\Http\Controllers\Web\WebGoogleAuthController;
use Spdotdev\Inventory\Http\Controllers\Web\WebHouseholdController;
use Spdotdev\Inventory\Http\Controllers\Web\WebLocationController;
use Spdotdev\Inventory\Http\Controllers\Web\WebProductController;
use Spdotdev\Inventory\Http\Controllers\Web\WebRestoreController;
use Spdotdev\Inventory\Http\Controllers\Web\WebSearchController;
use Spdotdev\Inventory\Http\Controllers\Web\WebShelfController;

// Marketing landing page + password reset form, served on the configured inventory host.
Route::domain(config('inventory.domain'))
    ->middleware(['web', 'inventory.locale'])
    ->group(function () {
        Route::get('/', [LandingController::class, 'index'])->name('inventory.landing');
        Route::get('/reset-password', [ResetPasswordController::class, 'show'])->name('inventory.reset-password');
        Route::post('/reset-password', [ResetPasswordController::class, 'update'])->name('inventory.reset-password.update');
        Route::get('/join/{code}', [JoinController::class, 'show'])->name('inventory.join');

        // Phase-2 web UI: session auth on the `inventory` guard (inventory_users).
        Route::middleware('throttle:inventory-auth')->group(function () {
            Route::post('/login', [WebAuthController::class, 'login'])->name('inventory.web.login');
            Route::post('/register', [WebAuthController::class, 'register'])->name('inventory.web.register');

            // Google sign-in (server-side redirect flow); 404s unless the web
            // client id + secret are configured. GETs — the throttle's
            // per-identity layer keys by IP here (no email input).
            Route::get('/auth/google', [WebGoogleAuthController::class, 'redirect'])->name('inventory.web.google.redirect');
            Route::get('/auth/google/callback', [WebGoogleAuthController::class, 'callback'])->name('inventory.web.google.callback');
        });
        Route::get('/login', [WebAuthController::class, 'showLogin'])->name('inventory.web.login.show');
        Route::get('/register', [WebAuthController::class, 'showRegister'])->name('inventory.web.register.show');

        Route::middleware('auth:inventory')->prefix('app')->group(function () {
            Route::post('/logout', [WebAuthController::class, 'logout'])->name('inventory.web.logout');
            Route::get('/households', [WebHouseholdController::class, 'index'])->name('inventory.web.households');
            Route::post('/households', [WebHouseholdController::class, 'store'])->name('inventory.web.households.store');
            Route::post('/households/join', [WebHouseholdController::class, 'join'])->name('inventory.web.households.join');
            Route::get('/households/{household}', [WebHouseholdController::class, 'show'])->name('inventory.web.households.show');
            Route::get('/households/{household}/export', [WebHouseholdController::class, 'export'])->name('inventory.web.households.export');
            Route::put('/households/{household}', [WebHouseholdController::class, 'update'])->name('inventory.web.households.update');
            Route::delete('/households/{household}/leave', [WebHouseholdController::class, 'leave'])->name('inventory.web.households.leave');
            Route::delete('/households/{household}', [WebHouseholdController::class, 'destroy'])->name('inventory.web.households.destroy');

            // Inventory CRUD (stage 2) — same tenancy gate + scoped bindings as /api/v1.
            Route::middleware('household.member')->scopeBindings()->group(function () {
                Route::get('/households/{household}/search', WebSearchController::class)->name('inventory.web.search');
                // Web parity T4: undo one deletion gesture (thin wrapper over
                // Support\Restorer, shared with the API). Literal "restore"
                // segment before the {batch} wildcard, same convention as
                // "reorder" above.
                Route::post('/households/{household}/restore/{batch}', WebRestoreController::class)->name('inventory.web.restore');
                Route::post('/households/{household}/locations', [WebLocationController::class, 'store'])->name('inventory.web.locations.store');
                // Literal segments precede the {location} wildcard routes below,
                // same convention as routes/api.php — otherwise "reorder" would
                // be swallowed as a {location} id.
                Route::patch('/households/{household}/locations/reorder', [WebLocationController::class, 'reorder'])->name('inventory.web.locations.reorder');
                Route::get('/households/{household}/locations/{location}', [WebLocationController::class, 'show'])->name('inventory.web.locations.show');
                Route::delete('/households/{household}/locations/{location}', [WebLocationController::class, 'destroy'])->name('inventory.web.locations.destroy');
                Route::patch('/households/{household}/locations/{location}/shelves/reorder', [WebShelfController::class, 'reorder'])->name('inventory.web.shelves.reorder');
                Route::post('/households/{household}/locations/{location}/shelves', [WebShelfController::class, 'store'])->name('inventory.web.shelves.store');
                Route::delete('/households/{household}/locations/{location}/shelves/{shelf}', [WebShelfController::class, 'destroy'])->name('inventory.web.shelves.destroy');
                Route::post('/households/{household}/shelves/{shelf}/products', [WebProductController::class, 'store'])->name('inventory.web.products.store');
                Route::get('/households/{household}/shelves/{shelf}/products/{product}/edit', [WebProductController::class, 'edit'])->name('inventory.web.products.edit');
                Route::put('/households/{household}/shelves/{shelf}/products/{product}', [WebProductController::class, 'update'])->name('inventory.web.products.update');
                Route::post('/households/{household}/shelves/{shelf}/products/{product}/image', [WebProductController::class, 'image'])->name('inventory.web.products.image');
                Route::post('/households/{household}/shelves/{shelf}/products/{product}/add', [WebProductController::class, 'add'])->name('inventory.web.products.add');
                Route::post('/households/{household}/shelves/{shelf}/products/{product}/remove', [WebProductController::class, 'remove'])->name('inventory.web.products.remove');
                Route::delete('/households/{household}/shelves/{shelf}/products/{product}', [WebProductController::class, 'destroy'])->name('inventory.web.products.destroy');
                Route::put('/households/{household}/members/{user}', [WebHouseholdController::class, 'updateMemberRole'])->name('inventory.web.members.update');
                Route::delete('/households/{household}/members/{user}', [WebHouseholdController::class, 'removeMember'])->name('inventory.web.members.remove');
                Route::post('/households/{household}/transfer-ownership', [WebHouseholdController::class, 'transferOwnership'])->name('inventory.web.households.transfer-ownership');
            });
        });
    });

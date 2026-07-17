<?php

use Illuminate\Support\Facades\Route;
use Spdotdev\Inventory\Http\Controllers\Api\AdminController;
use Spdotdev\Inventory\Http\Controllers\Api\AuthController;
use Spdotdev\Inventory\Http\Controllers\Api\ClientErrorController;
use Spdotdev\Inventory\Http\Controllers\Api\ForgotPasswordController;
use Spdotdev\Inventory\Http\Controllers\Api\HealthController;
use Spdotdev\Inventory\Http\Controllers\Api\HouseholdController;
use Spdotdev\Inventory\Http\Controllers\Api\LocationController;
use Spdotdev\Inventory\Http\Controllers\Api\MemberController;
use Spdotdev\Inventory\Http\Controllers\Api\ProductController;
use Spdotdev\Inventory\Http\Controllers\Api\RestoreController;
use Spdotdev\Inventory\Http\Controllers\Api\SearchController;
use Spdotdev\Inventory\Http\Controllers\Api\ShelfController;

// Headless API for the Android client. Versioned from day one; host-based
// routed on the configured inventory domain. Locations/shelves/products CRUD
// land here in the next step per the API contract.
Route::domain(config('inventory.domain'))
    ->prefix('api/v1')
    ->middleware('api')
    ->group(function () {
        Route::get('/health', HealthController::class)->name('inventory.api.health');
        // Unauthenticated crash intake — throttled per device+IP so it can't flood
        // the inventory_client_errors table (pruned by inventory:client-errors:prune).
        Route::post('/errors', ClientErrorController::class)
            ->middleware('throttle:inventory-errors')
            ->name('inventory.api.errors.store');

        // Admin API — protected by a static bearer token (INVENTORY_ADMIN_TOKEN).
        // Not tied to Sanctum user auth; intended for MCP / operator access only.
        Route::middleware('inventory.admin')->prefix('admin')->group(function () {
            Route::get('users', [AdminController::class, 'listUsers']);
            Route::get('users/search', [AdminController::class, 'searchUsers']);
            Route::get('users/{id}', [AdminController::class, 'getUser']);
            Route::delete('users/{id}', [AdminController::class, 'deleteUser']);

            Route::get('households', [AdminController::class, 'listHouseholds']);
            Route::get('households/{id}', [AdminController::class, 'getHousehold']);
            Route::delete('households/{id}', [AdminController::class, 'deleteHousehold']);
        });

        Route::prefix('auth')->group(function () {
            // Brute-force / credential-stuffing protection on the unauthenticated
            // entry points. logout is authenticated (token-bound) so it's exempt.
            Route::middleware('throttle:inventory-auth')->group(function () {
                Route::post('register', [AuthController::class, 'register'])->name('inventory.api.auth.register');
                Route::post('login', [AuthController::class, 'login'])->name('inventory.api.auth.login');
                Route::post('google', [AuthController::class, 'google'])->name('inventory.api.auth.google');
                Route::post('forgot-password', ForgotPasswordController::class)->name('inventory.api.auth.forgot-password');
            });
            Route::post('logout', [AuthController::class, 'logout'])
                ->middleware('auth:sanctum')
                ->name('inventory.api.auth.logout');
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('households', [HouseholdController::class, 'index'])->name('inventory.api.households.index');
            Route::post('households', [HouseholdController::class, 'store'])->name('inventory.api.households.store');
            // Defined before the {household} routes so "join" isn't captured as an id.
            // Throttled: join-by-code is guessable, so cap attempts per user.
            Route::post('households/join', [HouseholdController::class, 'join'])
                ->middleware('throttle:inventory-join')
                ->name('inventory.api.households.join');

            // Tenancy: household.member verifies the caller is a member;
            // scopeBindings verifies each nested resource belongs to its parent
            // (location ⊂ household, shelf ⊂ location/household, product ⊂ shelf).
            Route::middleware('household.member')->scopeBindings()->group(function () {
                Route::patch('households/{household}', [HouseholdController::class, 'update'])->name('inventory.api.households.update');
                Route::get('households/{household}/invite', [HouseholdController::class, 'invite'])->name('inventory.api.households.invite');
                Route::get('households/{household}/export', [HouseholdController::class, 'export'])->name('inventory.api.households.export');
                Route::delete('households/{household}/leave', [HouseholdController::class, 'leave'])->name('inventory.api.households.leave');
                Route::get('households/{household}/search', SearchController::class)->name('inventory.api.households.search');

                Route::get('households/{household}/members', [MemberController::class, 'index'])->name('inventory.api.members.index');
                Route::patch('households/{household}/members/{user}', [MemberController::class, 'update'])->name('inventory.api.members.update');
                Route::delete('households/{household}/members/{user}', [MemberController::class, 'destroy'])->name('inventory.api.members.destroy');
                Route::post('households/{household}/transfer-ownership', [MemberController::class, 'transferOwnership'])->name('inventory.api.households.transfer-ownership');

                // Keyed by batch, not by resource id — see RestoreController's
                // docblock for why a shelf/location/product-scoped restore route
                // could never be reached once the row is soft-deleted.
                Route::post('households/{household}/restore/{batch}', RestoreController::class)->name('inventory.api.restore');

                // Stock actions (defined before the resource so the /add|remove|move
                // suffixes aren't shadowed by the {product} show route).
                Route::post('households/{household}/shelves/{shelf}/products/{product}/add', [ProductController::class, 'add'])->name('inventory.api.products.add');
                Route::post('households/{household}/shelves/{shelf}/products/{product}/remove', [ProductController::class, 'remove'])->name('inventory.api.products.remove');
                Route::post('households/{household}/shelves/{shelf}/products/{product}/move', [ProductController::class, 'move'])->name('inventory.api.products.move');
                // Product photo upload (multipart). Stores on the configured disk and
                // populates image_url; the Android client posts a single "image" part.
                Route::post('households/{household}/shelves/{shelf}/products/{product}/image', [ProductController::class, 'image'])->name('inventory.api.products.image');

                // Literal segments must precede the apiResource, or `reorder` is
                // captured as {location} / {shelf}. Same rule as households/join.
                Route::patch('households/{household}/locations/reorder', [LocationController::class, 'reorder'])->name('inventory.api.locations.reorder');
                Route::patch('households/{household}/locations/{location}/shelves/reorder', [ShelfController::class, 'reorder'])->name('inventory.api.shelves.reorder');

                // Nested resource CRUD (apiResource = index/store/show/update/destroy).
                Route::apiResource('households.locations', LocationController::class)->shallow(false);
                Route::apiResource('households.locations.shelves', ShelfController::class)->shallow(false);
                Route::apiResource('households.shelves.products', ProductController::class)->shallow(false);
            });
        });
    });

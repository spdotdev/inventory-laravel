<?php

namespace Spdotdev\Inventory;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Spdotdev\Inventory\Auth\GoogleIdTokenVerifier;
use Spdotdev\Inventory\Auth\GoogleTokenInfoVerifier;
use Spdotdev\Inventory\Console\Commands\CreateHouseholdCommand;
use Spdotdev\Inventory\Http\Middleware\EnsureHouseholdMember;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/inventory.php', 'inventory');

        // Default Google ID-token verifier (tokeninfo endpoint). Swap the
        // binding to a local JWT-cert verifier if call volume warrants it.
        $this->app->bind(GoogleIdTokenVerifier::class, function ($app) {
            /** @var list<string> $clientIds */
            $clientIds = (array) $app['config']->get('inventory.google.client_ids', []);

            return new GoogleTokenInfoVerifier($clientIds);
        });
    }

    public function boot(): void
    {
        // Return 401 JSON for unauthenticated API requests instead of redirecting to a non-existent login route.
        $this->callAfterResolving(ExceptionHandler::class, function (ExceptionHandler $handler) {
            $handler->renderable(function (AuthenticationException $e, Request $request) {
                if ($request->is('api/*') || $request->expectsJson()) {
                    return response()->json(['message' => 'Unauthenticated.'], 401);
                }
            });
        });

        // Tenancy gate for /households/{household}/* routes.
        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('household.member', EnsureHouseholdMember::class);

        // Landing page (web) + headless API (api/v1), both host-based routed
        // on config('inventory.domain').
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'inventory');

        // Package-owned tables (inventory_*) live here; empty until the schema
        // step. loadMigrationsFrom is a no-op while the directory has none.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/inventory.php' => config_path('inventory.php'),
        ], 'inventory-config');

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/inventory'),
        ], 'inventory-assets');

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateHouseholdCommand::class,
            ]);
        }
    }
}

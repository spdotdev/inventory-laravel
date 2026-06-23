<?php

namespace Spdotdev\Inventory;

use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/inventory.php', 'inventory');
    }

    public function boot(): void
    {
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
    }
}

<?php

namespace Spdotdev\Inventory\Tests;

use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

/**
 * Test-only provider that registers Sanctum's personal_access_tokens migration
 * path. Sanctum 4 doesn't auto-load it in the testbench context. Registering it
 * here (rather than via testbench's defineDatabaseMigrations) means it runs only
 * when a test migrates via RefreshDatabase — non-DB tests stay DB-free.
 */
class SanctumMigrationsProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(
            dirname((new \ReflectionClass(Sanctum::class))->getFileName(), 2).'/database/migrations'
        );
    }
}

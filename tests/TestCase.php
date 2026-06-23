<?php

namespace Spdotdev\Inventory\Tests;

use Illuminate\Foundation\Application;
use Laravel\Sanctum\SanctumServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spdotdev\Inventory\InventoryServiceProvider;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        // SanctumServiceProvider: the `sanctum` guard the auth endpoints use.
        // SanctumMigrationsProvider: registers Sanctum's migration path (it
        //   isn't auto-loaded in testbench) so RefreshDatabase migrates it.
        return [
            SanctumServiceProvider::class,
            SanctumMigrationsProvider::class,
            InventoryServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // The web routes run in the `web` group, whose cookie encryption needs
        // an app key. Pin a deterministic one + a known inventory host so the
        // host-based routes resolve predictably in tests.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('inventory.domain', 'inventory.test');

        // In-memory SQLite with foreign keys enforced, so cascade-delete
        // behaviour is exercised in tests the way MySQL enforces it in prod.
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
    }
}

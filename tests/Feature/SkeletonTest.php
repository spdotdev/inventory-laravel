<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Spdotdev\Inventory\Tests\TestCase;

class SkeletonTest extends TestCase
{
    public function test_landing_page_renders_on_the_configured_host(): void
    {
        $this->get('http://inventory.test/')
            ->assertOk()
            ->assertSee('Inventory')
            ->assertSee('private preview');
    }

    public function test_api_health_endpoint_responds_under_v1(): void
    {
        $this->getJson('http://inventory.test/api/v1/health')
            ->assertOk()
            ->assertJson([
                'name' => 'inventory',
                'api' => 'v1',
                'status' => 'ok',
            ]);
    }

    public function test_landing_route_is_named(): void
    {
        $this->assertTrue(Route::has('inventory.landing'));
    }

    public function test_inventory_config_is_merged(): void
    {
        // The package's config is merged into the host config namespace; the
        // host-based routes read config('inventory.domain'). Here it's the
        // test host pinned in TestCase.
        $this->assertSame('inventory.test', config('inventory.domain'));
    }
}

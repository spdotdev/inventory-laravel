<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Mcp\InventoryAdminServer;
use Spdotdev\Inventory\Mcp\Tools\ListHouseholdsTool;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class McpToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_households_reports_real_counts(): void
    {
        // Regression: the tool read $h->storage_locations_count while withCount()
        // aliases the `locations` relation to `locations_count`, so every household
        // reported `locations: null`.
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'password' => bcrypt('secret123'),
        ]);
        $household = Household::query()->create([
            'name' => 'Home',
            'owner_id' => $owner->id,
            'join_code' => 'ABC12345',
        ]);
        $household->users()->attach($owner);
        $location = StorageLocation::query()->create([
            'household_id' => $household->id,
            'name' => 'Fridge',
            'type' => 'fridge',
        ]);
        Shelf::query()->create([
            'location_id' => $location->id,
            'name' => 'Top shelf',
        ]);

        InventoryAdminServer::tool(ListHouseholdsTool::class)
            ->assertOk()
            ->assertSee('"members":1')
            ->assertSee('"locations":1')
            ->assertSee('"shelves":1');
    }
}

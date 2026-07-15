<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Mcp\Request as McpRequest;
use Spdotdev\Inventory\Mcp\InventoryAdminServer;
use Spdotdev\Inventory\Mcp\Tools\DeleteHouseholdTool;
use Spdotdev\Inventory\Mcp\Tools\ListHouseholdsTool;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
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

    /**
     * Pre-existing bug: this tool calls Household::delete() directly (not the
     * admin HTTP endpoint), which ON DELETE CASCADEs its tree — dropping the
     * product row but leaving the file its image_url pointed at behind
     * forever. ReclaimHouseholdProductImages (a Household model observer)
     * must catch this call site too, not just the HTTP admin endpoint.
     *
     * Calls the tool's handle() directly rather than through
     * InventoryAdminServer::tool(): the full MCP dispatch pipeline resolves
     * Request via a resolving() callback laravel/mcp's own service provider
     * registers, which this package's test suite doesn't boot (see
     * TestCase::getPackageProviders — only Sanctum + this package are
     * listed), so arguments never reach handle() that way in THIS test
     * environment. Building the Request directly is what the tool actually
     * receives in production (the real server DOES boot that provider) and
     * keeps this test independent of that test-harness gap.
     */
    public function test_delete_household_tool_reclaims_products_stored_images(): void
    {
        Storage::fake('public');
        $owner = User::query()->create([
            'name' => 'Owner',
            'email' => 'owner2@example.com',
            'password' => bcrypt('secret123'),
        ]);
        $household = Household::query()->create([
            'name' => 'Home',
            'join_code' => 'DEF67890',
        ]);
        $household->users()->attach($owner);
        $location = StorageLocation::query()->create([
            'household_id' => $household->id,
            'name' => 'Fridge',
            'type' => 'fridge',
        ]);
        $shelf = Shelf::query()->create([
            'location_id' => $location->id,
            'name' => 'Top shelf',
        ]);
        $product = Product::query()->create([
            'shelf_id' => $shelf->id,
            'name' => 'Peas',
            'quantity' => 2,
        ]);

        Storage::disk('public')->put('inventory/products/peas.jpg', 'fake-image-bytes');
        $product->update(['image_url' => 'http://inventory.test/storage/inventory/products/peas.jpg']);
        Storage::disk('public')->assertExists('inventory/products/peas.jpg');

        (new DeleteHouseholdTool)->handle(new McpRequest(['id' => $household->id]));

        $this->assertDatabaseMissing('inventory_households', ['id' => $household->id]);
        $this->assertDatabaseMissing('inventory_products', ['id' => $product->id]);
        Storage::disk('public')->assertMissing('inventory/products/peas.jpg');
    }
}

<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class WebDeleteCascadeTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_location_on_the_web_takes_its_contents_with_it(): void
    {
        // Regression guard for the trap soft deletes introduced: $location->delete()
        // is now an UPDATE, so the ON DELETE CASCADE never fires. Without routing
        // through HierarchyDeleter, the shelf and product below would survive —
        // alive in the table, unreachable through any relation, and never purged,
        // because their own deleted_at stays null.
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $this->actingAs($user, 'inventory');

        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->delete("http://inventory.test/app/households/{$household->id}/locations/{$location->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
        $this->assertSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_deleting_a_shelf_on_the_web_takes_its_products_with_it(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $this->actingAs($user, 'inventory');

        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->delete("http://inventory.test/app/households/{$household->id}/locations/{$location->id}/shelves/{$shelf->id}")
            ->assertRedirect();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_a_web_delete_is_restorable_as_one_batch(): void
    {
        // The web UI mints its batch id server-side (it has no client to do it),
        // but the result must still be one restorable unit.
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $this->actingAs($user, 'inventory');

        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->delete("http://inventory.test/app/households/{$household->id}/locations/{$location->id}")
            ->assertRedirect();

        $batch = StorageLocation::withTrashed()
            ->findOrFail($location->id)
            ->deletion_batch_id;

        $this->assertNotNull($batch);

        // Every row killed by that one gesture carries the same id — a
        // refactor that stamped the product with a second, different uuid
        // would silently drop it out of the location's restore batch, and
        // the shelf-only assertion below would not have caught it.
        $this->assertDatabaseHas('inventory_shelves', ['id' => $shelf->id, 'deletion_batch_id' => $batch]);
        $this->assertDatabaseHas('inventory_products', ['id' => $product->id, 'deletion_batch_id' => $batch]);
    }
}

<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_full_tree_and_membership_can_be_built(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test']);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'FROST-7K2Q']);
        $household->users()->attach($user->id, ['joined_at' => now()]);

        $location = $household->locations()->create([
            'name' => 'Garage Chest',
            'type' => StorageType::Freezer,
        ]);
        $shelf = $location->shelves()->create(['name' => 'Top shelf', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 4]);

        $this->assertTrue($household->users->contains($user));
        $this->assertTrue($user->households->contains($household));
        $this->assertSame(StorageType::Freezer, $location->fresh()->type);
        $this->assertSame(4, $product->fresh()->quantity);
        $this->assertSame($location->id, $shelf->location->id);
        $this->assertSame($shelf->id, $product->shelf->id);
    }

    public function test_quantity_defaults_to_zero(): void
    {
        $shelf = $this->makeShelf();
        $product = $shelf->products()->create(['name' => 'Stock cubes']);

        $this->assertSame(0, $product->fresh()->quantity);
    }

    public function test_soft_deleting_a_location_does_not_cascade_to_shelves_and_products(): void
    {
        // A soft delete is an UPDATE, not a DELETE, so it never trips the
        // ON DELETE CASCADE foreign keys — that's what makes it undoable. The
        // shelf/product rows are left exactly as they were; SoftDeleteTest
        // covers how they become unreachable via the household/API relations.
        $shelf = $this->makeShelf();
        $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);
        $location = $shelf->location;

        $location->delete();

        $this->assertDatabaseCount('inventory_shelves', 1);
        $this->assertDatabaseCount('inventory_products', 1);
    }

    public function test_force_deleting_a_location_cascades_to_shelves_and_products(): void
    {
        // The ON DELETE CASCADE foreign keys are kept specifically for the
        // eventual hard purge — this pins that forceDelete() (a real DELETE)
        // still tears down the whole subtree.
        $shelf = $this->makeShelf();
        $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);
        $location = $shelf->location;

        $location->forceDelete();

        $this->assertDatabaseCount('inventory_shelves', 0);
        $this->assertDatabaseCount('inventory_products', 0);
    }

    public function test_deleting_a_household_cascades_to_its_locations(): void
    {
        $shelf = $this->makeShelf();
        $household = $shelf->location->household;

        $household->delete();

        $this->assertDatabaseCount('inventory_storage_locations', 0);
        $this->assertDatabaseCount('inventory_shelves', 0);
    }

    private function makeShelf(): Shelf
    {
        $household = Household::create(['name' => 'Garage', 'join_code' => 'CODE-'.uniqid()]);
        $location = $household->locations()->create([
            'name' => 'Chest',
            'type' => StorageType::Freezer,
        ]);

        return $location->shelves()->create(['name' => 'Top', 'position' => 0]);
    }
}

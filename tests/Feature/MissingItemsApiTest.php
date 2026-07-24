<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class MissingItemsApiTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private function makeShelf(Household $household): Shelf
    {
        $location = StorageLocation::query()->create([
            'household_id' => $household->id,
            'name' => 'Fridge',
            'type' => 'fridge',
        ]);

        return Shelf::query()->create([
            'location_id' => $location->id,
            'name' => 'Top shelf',
        ]);
    }

    public function test_count_reflects_missing_items_across_all_the_users_households(): void
    {
        $user = User::query()->create([
            'name' => 'Stan',
            'email' => 'stan@example.test',
            'password' => bcrypt('secret123'),
        ]);

        $householdA = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA1111']);
        $householdA->users()->attach($user);
        $shelfA = $this->makeShelf($householdA);
        Product::query()->create([
            'shelf_id' => $shelfA->id,
            'name' => 'Milk',
            'is_mandatory' => true,
            'quantity' => 0,
        ]);
        // Not mandatory — must not count.
        Product::query()->create([
            'shelf_id' => $shelfA->id,
            'name' => 'Snacks',
            'is_mandatory' => false,
            'quantity' => 0,
        ]);
        // Mandatory but in stock — must not count.
        Product::query()->create([
            'shelf_id' => $shelfA->id,
            'name' => 'Bread',
            'is_mandatory' => true,
            'quantity' => 3,
        ]);

        $householdB = Household::query()->create(['name' => 'Cabin', 'join_code' => 'BBBB2222']);
        $householdB->users()->attach($user);
        $shelfB = $this->makeShelf($householdB);
        Product::query()->create([
            'shelf_id' => $shelfB->id,
            'name' => 'Eggs',
            'is_mandatory' => true,
            'quantity' => 0,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("{$this->base}/missing-items/count")
            ->assertOk()
            ->assertJsonPath('data.count', 2);
    }

    public function test_count_excludes_other_users_households(): void
    {
        $user = User::query()->create([
            'name' => 'Stan',
            'email' => 'stan@example.test',
            'password' => bcrypt('secret123'),
        ]);
        $otherUser = User::query()->create([
            'name' => 'Alex',
            'email' => 'alex@example.test',
            'password' => bcrypt('secret123'),
        ]);

        $otherHousehold = Household::query()->create(['name' => 'Not Mine', 'join_code' => 'CCCC3333']);
        $otherHousehold->users()->attach($otherUser);
        $shelf = $this->makeShelf($otherHousehold);
        Product::query()->create([
            'shelf_id' => $shelf->id,
            'name' => 'Milk',
            'is_mandatory' => true,
            'quantity' => 0,
        ]);

        Sanctum::actingAs($user);

        $this->getJson("{$this->base}/missing-items/count")
            ->assertOk()
            ->assertJsonPath('data.count', 0);
    }

    public function test_count_requires_authentication(): void
    {
        $this->getJson("{$this->base}/missing-items/count")->assertStatus(401);
    }
}

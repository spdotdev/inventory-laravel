<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    private function seedHouseholdWithProduct(User $member): Household
    {
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($member->getKey(), ['joined_at' => now()]);

        $location = $household->locations()->create(['name' => 'Garage Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Middle shelf', 'position' => 1]);
        $shelf->products()->create(['name' => 'Vanilla ice cream', 'quantity' => 1]);

        return $household;
    }

    public function test_search_returns_matches_with_the_location_path(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = $this->seedHouseholdWithProduct($user);

        $location = $household->locations()->first();
        $shelf = $location->shelves()->first();

        $this->getJson("http://inventory.test/api/v1/households/{$household->id}/search?q=ice")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Vanilla ice cream')
            ->assertJsonPath('data.0.quantity', 1)
            ->assertJsonPath('data.0.path', 'Garage Chest › Middle shelf')
            // Nav IDs the client needs to make a hit tappable (W1) — without these
            // every result is a dead card against the real backend.
            ->assertJsonPath('data.0.household_id', $household->id)
            ->assertJsonPath('data.0.location_id', $location->id)
            ->assertJsonPath('data.0.shelf_id', $shelf->id);
    }

    public function test_search_does_not_match_other_terms(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = $this->seedHouseholdWithProduct($user);

        $this->getJson("http://inventory.test/api/v1/households/{$household->id}/search?q=pizza")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_non_member_cannot_search(): void
    {
        $owner = User::create(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'secret-password']);
        $household = $this->seedHouseholdWithProduct($owner);

        $outsider = User::create(['name' => 'Out', 'email' => 'out@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($outsider);

        $this->getJson("http://inventory.test/api/v1/households/{$household->id}/search?q=ice")
            ->assertNotFound();
    }
}

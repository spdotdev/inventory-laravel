<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * Web parity Task 3: complete delete strategies (move_products/move_contents)
 * on the web, plus the fold-in fix — WebLocationController/WebShelfController
 * store()/destroy() were missing the `restructure` gate the API and this
 * surface's own update()/reorder() already carry. Both destroy() methods now
 * validate through the SAME Form Requests as the API (DeleteLocationRequest /
 * DeleteShelfRequest), so this mirrors the invariant matrix in
 * LocationDeleteStrategyTest.php / ShelfDeleteStrategyTest.php via the web
 * routes, rather than re-deriving it.
 */
class WebDeleteStrategyTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/app';

    private function user(string $email = 'web@example.test'): User
    {
        return User::create(['name' => 'Web', 'email' => $email, 'password' => bcrypt('secret-password')]);
    }

    /** @return array{0: User, 1: Household} */
    private function household(string $role = 'admin'): array
    {
        $user = $this->user();
        $household = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => $role]);

        return [$user, $household];
    }

    // --- move_products (shelf) -------------------------------------------

    public function test_move_products_relocates_products_to_the_chosen_shelf(): void
    {
        [$user, $household] = $this->household();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $source = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $target = $location->shelves()->create(['name' => 'Bottom', 'position' => 1]);
        $product = $source->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->actingAs($user, 'inventory')
            ->delete("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$source->id}", [
                'strategy' => 'move_products',
                'target_shelf_id' => $target->id,
            ])
            ->assertRedirect(route('inventory.web.locations.show', [$household, $location]));

        $this->assertSoftDeleted('inventory_shelves', ['id' => $source->id]);
        $this->assertDatabaseHas('inventory_products', ['id' => $product->id, 'shelf_id' => $target->id, 'deleted_at' => null]);
    }

    public function test_move_products_to_a_shelf_in_another_household_is_rejected(): void
    {
        [$user, $household] = $this->household();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $source = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $source->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $foreign = $other->locations()->create(['name' => 'X', 'type' => StorageType::Pantry])
            ->shelves()->create(['name' => 'Y', 'position' => 0]);

        $this->actingAs($user, 'inventory')
            ->delete("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$source->id}", [
                'strategy' => 'move_products',
                'target_shelf_id' => $foreign->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('target_shelf_id');

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $source->id]);
    }

    public function test_move_products_to_the_shelf_being_deleted_is_rejected(): void
    {
        [$user, $household] = $this->household();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $source = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $source->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->actingAs($user, 'inventory')
            ->delete("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$source->id}", [
                'strategy' => 'move_products',
                'target_shelf_id' => $source->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('target_shelf_id');

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $source->id]);
    }

    public function test_a_shelf_with_no_target_available_does_not_offer_move_products_in_the_dialog(): void
    {
        // No-target households don't offer move — pinned as a view test on
        // the rendered options, per the plan's test matrix. This shelf is
        // the ONLY shelf in the household, so $allShelves (minus self) is
        // empty and the partial must drop the move_products radio entirely.
        [$user, $household] = $this->household();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $response = $this->actingAs($user, 'inventory')
            ->get(route('inventory.web.locations.show', [$household, $location]))
            ->assertOk();

        $response->assertDontSee('Move products to another shelf');
        $response->assertSee('Keep products here (move to Unsorted)');
    }

    public function test_a_shelf_with_a_target_available_offers_move_products_in_the_dialog(): void
    {
        [$user, $household] = $this->household();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $location->shelves()->create(['name' => 'Bottom', 'position' => 1]);
        $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $response = $this->actingAs($user, 'inventory')
            ->get(route('inventory.web.locations.show', [$household, $location]))
            ->assertOk();

        $response->assertSee('Move products to another shelf');
    }

    // --- move_contents (location) -----------------------------------------

    public function test_move_contents_relocates_shelves_to_the_chosen_location(): void
    {
        [$user, $household] = $this->household();
        $source = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $target = $household->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);
        $shelf = $source->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->actingAs($user, 'inventory')
            ->delete("{$this->base}/households/{$household->id}/locations/{$source->id}", [
                'strategy' => 'move_contents',
                'target_location_id' => $target->id,
            ])
            ->assertRedirect(route('inventory.web.households.show', $household));

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $source->id]);
        $this->assertDatabaseHas('inventory_shelves', ['id' => $shelf->id, 'location_id' => $target->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('inventory_products', ['id' => $product->id, 'shelf_id' => $shelf->id, 'deleted_at' => null]);
    }

    public function test_move_contents_to_a_location_in_another_household_is_rejected(): void
    {
        [$user, $household] = $this->household();
        $source = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $source->shelves()->create(['name' => 'Top', 'position' => 0]);

        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $foreign = $other->locations()->create(['name' => 'Theirs', 'type' => StorageType::Pantry]);

        $this->actingAs($user, 'inventory')
            ->delete("{$this->base}/households/{$household->id}/locations/{$source->id}", [
                'strategy' => 'move_contents',
                'target_location_id' => $foreign->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('target_location_id');

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $source->id]);
    }

    public function test_move_contents_to_the_location_being_deleted_is_rejected(): void
    {
        [$user, $household] = $this->household();
        $source = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $source->shelves()->create(['name' => 'Top', 'position' => 0]);

        $this->actingAs($user, 'inventory')
            ->delete("{$this->base}/households/{$household->id}/locations/{$source->id}", [
                'strategy' => 'move_contents',
                'target_location_id' => $source->id,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('target_location_id');

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $source->id]);
    }

    public function test_a_household_with_only_one_location_does_not_offer_move_contents(): void
    {
        // No-target households don't offer move — view test on rendered
        // options, on BOTH pages that now carry this dialog.
        [$user, $household] = $this->household();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $location->shelves()->create(['name' => 'Top', 'position' => 0]);

        $onLocationPage = $this->actingAs($user, 'inventory')
            ->get(route('inventory.web.locations.show', [$household, $location]))
            ->assertOk();
        $onLocationPage->assertDontSee('Move shelves to another location');
        $onLocationPage->assertSee('Delete everything with it');

        $onHouseholdPage = $this->actingAs($user, 'inventory')
            ->get(route('inventory.web.households.show', $household))
            ->assertOk();
        $onHouseholdPage->assertDontSee('Move shelves to another location');
    }

    public function test_a_household_with_a_second_location_offers_move_contents_on_both_pages(): void
    {
        [$user, $household] = $this->household();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $household->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);

        $onLocationPage = $this->actingAs($user, 'inventory')
            ->get(route('inventory.web.locations.show', [$household, $location]))
            ->assertOk();
        $onLocationPage->assertSee('Move shelves to another location');

        $onHouseholdPage = $this->actingAs($user, 'inventory')
            ->get(route('inventory.web.households.show', $household))
            ->assertOk();
        $onHouseholdPage->assertSee('Move shelves to another location');
    }

    // --- fold-in: restructure gate on store()/destroy() --------------------

    public function test_a_member_cannot_create_a_location_via_the_web(): void
    {
        [$user, $household] = $this->household('member');

        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.locations.store', $household), ['name' => 'Pantry', 'type' => 'pantry'])
            ->assertForbidden();

        $this->assertDatabaseMissing('inventory_storage_locations', ['name' => 'Pantry']);
    }

    public function test_an_admin_can_create_a_location_via_the_web(): void
    {
        [$user, $household] = $this->household('admin');

        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.locations.store', $household), ['name' => 'Pantry', 'type' => 'pantry'])
            ->assertRedirect();

        $this->assertDatabaseHas('inventory_storage_locations', ['name' => 'Pantry']);
    }

    public function test_a_member_cannot_delete_a_location_via_the_web(): void
    {
        [$user, $household] = $this->household('member');
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);

        $this->actingAs($user, 'inventory')
            ->delete(route('inventory.web.locations.destroy', [$household, $location]))
            ->assertForbidden();

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_an_admin_can_delete_a_location_via_the_web(): void
    {
        [$user, $household] = $this->household('admin');
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);

        $this->actingAs($user, 'inventory')
            ->delete(route('inventory.web.locations.destroy', [$household, $location]))
            ->assertRedirect();

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_a_member_cannot_create_a_shelf_via_the_web(): void
    {
        [$user, $household] = $this->household('member');
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);

        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.shelves.store', [$household, $location]), ['name' => 'Top'])
            ->assertForbidden();

        $this->assertDatabaseMissing('inventory_shelves', ['name' => 'Top']);
    }

    public function test_an_admin_can_create_a_shelf_via_the_web(): void
    {
        [$user, $household] = $this->household('admin');
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);

        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.shelves.store', [$household, $location]), ['name' => 'Top'])
            ->assertRedirect();

        $this->assertDatabaseHas('inventory_shelves', ['name' => 'Top']);
    }

    public function test_a_member_cannot_delete_a_shelf_via_the_web(): void
    {
        [$user, $household] = $this->household('member');
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);

        $this->actingAs($user, 'inventory')
            ->delete(route('inventory.web.shelves.destroy', [$household, $location, $shelf]))
            ->assertForbidden();

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
    }

    public function test_an_admin_can_delete_a_shelf_via_the_web(): void
    {
        [$user, $household] = $this->household('admin');
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);

        $this->actingAs($user, 'inventory')
            ->delete(route('inventory.web.shelves.destroy', [$household, $location, $shelf]))
            ->assertRedirect();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
    }
}

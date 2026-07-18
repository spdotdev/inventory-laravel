<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * GAP-8 web parity: rename a location/shelf and move a product between
 * shelves on the web — previously the web's only recourse for a typo or a
 * misfiled product was delete-and-recreate (losing image/star/threshold),
 * while the API had all three since MVP.
 */
class WebRenameMoveTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/app';

    private function member(string $role = 'admin'): array
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $this->actingAs($user, 'inventory');

        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => $role]);

        return [$user, $household];
    }

    public function test_a_location_can_be_renamed_and_retyped_on_the_web(): void
    {
        [, $household] = $this->member();
        $location = $household->locations()->create(['name' => 'Freezr', 'type' => StorageType::Freezer]);

        $this->put("{$this->base}/households/{$household->id}/locations/{$location->id}", [
            'name' => 'Freezer',
            'type' => StorageType::Fridge->value,
        ])->assertRedirect();

        $location->refresh();
        $this->assertSame('Freezer', $location->name);
        $this->assertSame(StorageType::Fridge, $location->type);
    }

    public function test_a_member_cannot_rename_a_location(): void
    {
        [, $household] = $this->member(role: 'member');
        $location = $household->locations()->create(['name' => 'Freezr', 'type' => StorageType::Freezer]);

        $this->put("{$this->base}/households/{$household->id}/locations/{$location->id}", [
            'name' => 'Freezer',
        ])->assertForbidden();
    }

    public function test_a_shelf_can_be_renamed_but_the_system_shelf_cannot(): void
    {
        [, $household] = $this->member();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Tob', 'position' => 0]);
        $system = $location->shelves()->create(['name' => 'Unsorted', 'position' => 1, 'is_system' => true]);

        $this->put("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'name' => 'Top',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame('Top', $shelf->refresh()->name);

        $this->put("{$this->base}/households/{$household->id}/locations/{$location->id}/shelves/{$system->id}", [
            'name' => 'Bananas',
        ])->assertRedirect()->assertSessionHasErrors('name');

        $this->assertSame('Unsorted', $system->refresh()->name);
    }

    public function test_a_product_can_be_moved_to_another_shelf_in_the_household(): void
    {
        [, $household] = $this->member(role: 'member');
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $from = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $to = $location->shelves()->create(['name' => 'Bottom', 'position' => 1]);
        $product = $from->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->post("{$this->base}/households/{$household->id}/shelves/{$from->id}/products/{$product->id}/move", [
            'shelf_id' => $to->id,
        ])->assertRedirect("{$this->base}/households/{$household->id}/shelves/{$to->id}/products/{$product->id}/edit");

        $this->assertSame($to->id, $product->refresh()->shelf_id);
    }

    public function test_a_product_cannot_be_moved_into_another_households_shelf(): void
    {
        [, $household] = $this->member();
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $from = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $from->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $other = Household::create(['name' => 'Other', 'join_code' => 'BBBB-2222']);
        $otherShelf = $other->locations()->create(['name' => 'X', 'type' => StorageType::Pantry])
            ->shelves()->create(['name' => 'Y', 'position' => 0]);

        $this->from("{$this->base}/households/{$household->id}/shelves/{$from->id}/products/{$product->id}/edit")
            ->post("{$this->base}/households/{$household->id}/shelves/{$from->id}/products/{$product->id}/move", [
                'shelf_id' => $otherShelf->id,
            ])->assertRedirect()->assertSessionHasErrors('shelf_id');

        $this->assertSame($from->id, $product->refresh()->shelf_id);
    }
}

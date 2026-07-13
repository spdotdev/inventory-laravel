<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class ShelfDeleteStrategyTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private string $batch = '11111111-1111-4111-8111-111111111111';

    /** @return array{Household, StorageLocation, Shelf, Product} */
    private function shelfWithProduct(): array
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);
        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        return [$household, $location, $shelf, $product];
    }

    private function url(Household $h, StorageLocation $l, Shelf $s): string
    {
        return "{$this->base}/households/{$h->id}/locations/{$l->id}/shelves/{$s->id}";
    }

    public function test_deleting_an_occupied_shelf_without_a_strategy_is_rejected(): void
    {
        // This is the bug the whole spec exists to fix: today this call silently
        // hard-deletes the product. The server must refuse to guess.
        [$h, $l, $s, $p] = $this->shelfWithProduct();

        $this->deleteJson($this->url($h, $l, $s), ['deletion_batch_id' => $this->batch])
            ->assertStatus(422)
            ->assertJsonValidationErrors('strategy');

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $s->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $p->id]);
    }

    public function test_an_empty_shelf_needs_no_strategy(): void
    {
        [$h, $l, $s] = $this->shelfWithProduct();
        Product::query()->delete();

        $this->deleteJson($this->url($h, $l, $s), ['deletion_batch_id' => $this->batch])->assertOk();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id]);
    }

    public function test_move_products_reassigns_them_to_the_target_shelf(): void
    {
        [$h, $l, $s, $p] = $this->shelfWithProduct();
        $target = $l->shelves()->create(['name' => 'Bottom', 'position' => 1]);

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'move_products',
            'target_shelf_id' => $target->id,
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id]);
        $this->assertDatabaseHas('inventory_products', ['id' => $p->id, 'shelf_id' => $target->id, 'deleted_at' => null]);
    }

    public function test_unsort_products_moves_them_to_the_unsorted_shelf(): void
    {
        [$h, $l, $s, $p] = $this->shelfWithProduct();

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'unsort_products',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $unsorted = $l->shelves()->where('is_system', true)->firstOrFail();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id]);
        $this->assertDatabaseHas('inventory_products', ['id' => $p->id, 'shelf_id' => $unsorted->id, 'deleted_at' => null]);
    }

    public function test_delete_products_soft_deletes_them_in_the_same_batch(): void
    {
        [$h, $l, $s, $p] = $this->shelfWithProduct();

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'delete_products',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        // Same batch id on both rows — that is what lets one Undo bring back the
        // shelf AND its products as a unit.
        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id, 'deletion_batch_id' => $this->batch]);
        $this->assertSoftDeleted('inventory_products', ['id' => $p->id, 'deletion_batch_id' => $this->batch]);
    }

    public function test_move_to_a_shelf_in_another_household_is_rejected(): void
    {
        [$h, $l, $s, $p] = $this->shelfWithProduct();
        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $foreign = $other->locations()->create(['name' => 'X', 'type' => StorageType::Pantry])
            ->shelves()->create(['name' => 'Y', 'position' => 0]);

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'move_products',
            'target_shelf_id' => $foreign->id,
            'deletion_batch_id' => $this->batch,
        ])->assertStatus(422)->assertJsonValidationErrors('target_shelf_id');

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $s->id]);
    }

    public function test_move_products_to_the_shelf_being_deleted_is_rejected(): void
    {
        [$h, $l, $s] = $this->shelfWithProduct();

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'move_products',
            'target_shelf_id' => $s->id,
            'deletion_batch_id' => $this->batch,
        ])->assertStatus(422)->assertJsonValidationErrors('target_shelf_id');
    }
}

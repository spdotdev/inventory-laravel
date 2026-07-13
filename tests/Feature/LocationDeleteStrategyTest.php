<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class LocationDeleteStrategyTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private string $batch = '22222222-2222-4222-8222-222222222222';

    private function memberHousehold(): Household
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        return $household;
    }

    /** A location holding one shelf with one product on it. */
    private function stockedLocation(Household $h, string $name = 'Chest'): StorageLocation
    {
        $location = $h->locations()->create(['name' => $name, 'type' => StorageType::Freezer]);
        $location->shelves()->create(['name' => 'Top', 'position' => 0])
            ->products()->create(['name' => 'Peas', 'quantity' => 2]);

        return $location;
    }

    public function test_deleting_a_stocked_location_without_a_strategy_is_rejected(): void
    {
        $h = $this->memberHousehold();
        $location = $this->stockedLocation($h);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'deletion_batch_id' => $this->batch,
        ])->assertStatus(422)->assertJsonValidationErrors('strategy');

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_move_contents_reparents_the_shelves(): void
    {
        // This is why shelf reparenting exists at all: "move this fridge's
        // contents to the pantry" IS reparenting its shelves. The products come
        // along for free — they hang off the shelf, which never changed identity.
        $h = $this->memberHousehold();
        $source = $this->stockedLocation($h, 'Chest');
        $target = $h->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);

        $shelf = $source->shelves()->firstOrFail();
        $product = $shelf->products()->firstOrFail();

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$source->id}", [
            'strategy' => 'move_contents',
            'target_location_id' => $target->id,
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $source->id]);
        $this->assertDatabaseHas('inventory_shelves', ['id' => $shelf->id, 'location_id' => $target->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('inventory_products', ['id' => $product->id, 'shelf_id' => $shelf->id, 'deleted_at' => null]);
    }

    public function test_delete_contents_soft_deletes_the_whole_subtree_in_one_batch(): void
    {
        $h = $this->memberHousehold();
        $location = $this->stockedLocation($h);
        $shelf = $location->shelves()->firstOrFail();
        $product = $shelf->products()->firstOrFail();

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'strategy' => 'delete_contents',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        // All three levels, one batch — so one Undo brings the whole fridge back.
        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id, 'deletion_batch_id' => $this->batch]);
        $this->assertSoftDeleted('inventory_shelves', ['id' => $shelf->id, 'deletion_batch_id' => $this->batch]);
        $this->assertSoftDeleted('inventory_products', ['id' => $product->id, 'deletion_batch_id' => $this->batch]);
    }

    public function test_an_empty_location_needs_no_strategy(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Empty', 'type' => StorageType::Other]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_move_contents_to_a_location_in_another_household_is_rejected(): void
    {
        $h = $this->memberHousehold();
        $source = $this->stockedLocation($h);
        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $foreign = $other->locations()->create(['name' => 'Theirs', 'type' => StorageType::Pantry]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$source->id}", [
            'strategy' => 'move_contents',
            'target_location_id' => $foreign->id,
            'deletion_batch_id' => $this->batch,
        ])->assertStatus(422)->assertJsonValidationErrors('target_location_id');
    }

    public function test_a_shelf_can_be_reparented_to_another_location(): void
    {
        $h = $this->memberHousehold();
        $source = $this->stockedLocation($h, 'Chest');
        $target = $h->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);
        $shelf = $source->shelves()->firstOrFail();

        $this->patchJson("{$this->base}/households/{$h->id}/locations/{$source->id}/shelves/{$shelf->id}", [
            'location_id' => $target->id,
        ])->assertOk()->assertJsonPath('data.location_id', $target->id);
    }

    public function test_a_shelf_cannot_be_reparented_into_another_household(): void
    {
        $h = $this->memberHousehold();
        $source = $this->stockedLocation($h);
        $shelf = $source->shelves()->firstOrFail();

        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $foreign = $other->locations()->create(['name' => 'Theirs', 'type' => StorageType::Pantry]);

        $this->patchJson("{$this->base}/households/{$h->id}/locations/{$source->id}/shelves/{$shelf->id}", [
            'location_id' => $foreign->id,
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');
    }

    public function test_delete_without_a_batch_id_is_rejected(): void
    {
        // Mutation-proof: relaxing 'deletion_batch_id' => ['required', 'uuid']
        // to ['nullable'] must not slip past the whole suite unnoticed. See
        // Task 5's identical guard on DeleteShelfRequest.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Empty', 'type' => StorageType::Other]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('deletion_batch_id');

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_delete_with_a_non_uuid_batch_id_is_rejected(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Empty', 'type' => StorageType::Other]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'deletion_batch_id' => 'not-a-uuid',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('deletion_batch_id');

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_delete_broadcasts_to_the_household_exactly_once(): void
    {
        // HierarchyDeleter::deleteLocation's writes are all query-builder writes
        // (no Eloquent events), so it must dispatch HouseholdChanged itself —
        // exactly once. move_contents is the strategy that pins this hardest:
        // it reparents shelves via a query-builder update, not Eloquent.
        Event::fake([HouseholdChanged::class]);
        $h = $this->memberHousehold();
        $source = $this->stockedLocation($h);
        $target = $h->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);

        Event::fake([HouseholdChanged::class]); // reset: the creates above already pinged

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$source->id}", [
            'strategy' => 'move_contents',
            'target_location_id' => $target->id,
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        Event::assertDispatchedTimes(HouseholdChanged::class, 1);
    }
}

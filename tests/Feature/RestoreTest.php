<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class RestoreTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private string $batch = '33333333-3333-4333-8333-333333333333';

    private function memberHousehold(string $email = 'stan@example.test', string $code = 'AAAA-1111'): Household
    {
        $user = User::create(['name' => 'Stan', 'email' => $email, 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => $code]);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        return $household;
    }

    public function test_restoring_a_batch_brings_back_the_shelf_and_its_products(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'strategy' => 'delete_products',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertOk()
            ->assertJsonPath('restored', 2);

        // The whole gesture comes back as a unit — that is the point of the batch.
        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_restoring_a_location_batch_brings_back_the_whole_subtree(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'strategy' => 'delete_contents',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertOk()
            ->assertJsonPath('restored', 3);

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_restoring_an_unknown_batch_is_a_409(): void
    {
        $h = $this->memberHousehold();

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertStatus(409);
    }

    public function test_a_batch_from_another_household_cannot_be_restored(): void
    {
        // Batch ids are client-minted, so a malicious client could guess one.
        // Restoring must be scoped to rows in the caller's own household.
        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $foreign = $other->locations()->create(['name' => 'Theirs', 'type' => StorageType::Pantry]);
        $foreign->deletion_batch_id = $this->batch;
        $foreign->save();
        $foreign->delete();

        $h = $this->memberHousehold();

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertStatus(409);

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $foreign->id]);
    }

    public function test_restore_broadcasts_household_changed_exactly_once(): void
    {
        // HierarchyDeleter's writes and the restore's own writes are both
        // query-builder updates, which fire no Eloquent events — so the
        // controller must dispatch HouseholdChanged itself. Other members'
        // screens rely on exactly one ping per gesture to know to refetch.
        Event::fake([HouseholdChanged::class]);

        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'strategy' => 'delete_products',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        Event::fake([HouseholdChanged::class]); // reset: the creates + delete above already pinged

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertOk();

        Event::assertDispatchedTimes(HouseholdChanged::class, 1);
        Event::assertDispatched(
            HouseholdChanged::class,
            fn (HouseholdChanged $e) => $e->householdId === (int) $h->id,
        );
    }
}

<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_restoring_a_shelf_is_blocked_when_its_location_died_in_a_later_batch(): void
    {
        // C-1 reproduction #1: delete the shelf (+ its product) first, batch A.
        // Then delete the LOCATION with delete_contents, batch B. HierarchyDeleter
        // walks $location->shelves(), which the SoftDeletes global scope filters —
        // it never sees the already-trashed shelf, so only the location itself
        // gets stamped B; the shelf (and its product) keep batch A. Restoring A
        // must NOT resurrect the shelf/product under a location that is still
        // very much dead.
        $otherBatch = '44444444-4444-4444-8444-444444444444';

        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'strategy' => 'delete_products',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'strategy' => 'delete_contents',
            'deletion_batch_id' => $otherBatch,
        ])->assertOk();

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertStatus(409);

        // Nothing was resurrected: the shelf and product stay dead, and so does
        // the location (untouched by this call — it belongs to $otherBatch).
        $this->assertSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertSoftDeleted('inventory_products', ['id' => $product->id]);
        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_restoring_a_product_is_blocked_when_its_shelf_died_in_a_later_batch(): void
    {
        // C-1 reproduction #2, one level down: delete a product on its own
        // (batch A), then delete its shelf (batch B). The trashed product keeps
        // batch A. Restoring A must not resurrect the product under a shelf
        // that is still dead — that product would be live but unreachable
        // (no household_id column; only ever reached by walking down from a
        // live shelf), and later permanently destroyed when the retention purge
        // force-deletes the still-trashed shelf and ON DELETE CASCADE fires.
        $otherBatch = '55555555-5555-4555-8555-555555555555';

        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        // A solo product delete has no client-supplied deletion_batch_id — the
        // server mints a batch-of-one and hands it back (see ProductController::
        // destroy()); it must be read from the response, not invented.
        $productDeleteResponse = $this->deleteJson("{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products/{$product->id}")
            ->assertOk();

        $productBatch = $productDeleteResponse->json('deletion_batch_id');

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'strategy' => 'delete_products',
            'deletion_batch_id' => $otherBatch,
        ])->assertOk();

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$productBatch}")
            ->assertStatus(409);

        $this->assertSoftDeleted('inventory_products', ['id' => $product->id]);
        $this->assertSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
    }

    public function test_a_foreign_shelf_and_product_cannot_be_restored_via_a_guessed_batch(): void
    {
        // I-1: the existing cross-household test only plants a foreign
        // LOCATION, which is guarded by the household_id filter on
        // StorageLocation — a completely different line from the ones that
        // scope shelves/products (which walk down from the caller's own
        // location/shelf ids, since neither table carries a household_id).
        // Keep the foreign location LIVE here so that guard cannot be what
        // blocks this request — only the shelf/product scoping can.
        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $foreignLocation = $other->locations()->create(['name' => 'Theirs', 'type' => StorageType::Pantry]);
        $foreignShelf = $foreignLocation->shelves()->create(['name' => 'Shelf', 'position' => 0]);
        $foreignProduct = $foreignShelf->products()->create(['name' => 'Beans', 'quantity' => 1]);

        $foreignShelf->deletion_batch_id = $this->batch;
        $foreignShelf->save();
        $foreignShelf->delete();

        $foreignProduct->deletion_batch_id = $this->batch;
        $foreignProduct->save();
        $foreignProduct->delete();

        $h = $this->memberHousehold();

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertStatus(409);

        $this->assertSoftDeleted('inventory_shelves', ['id' => $foreignShelf->id]);
        $this->assertSoftDeleted('inventory_products', ['id' => $foreignProduct->id]);
    }

    public function test_a_rolled_back_restore_never_broadcasts(): void
    {
        // M-1: mirrors LocationDeleteStrategyTest::test_a_rolled_back_delete_never_broadcasts.
        // assertDispatchedTimes(..., 1) only discriminates on COUNT, not
        // ordering — moving the dispatch INSIDE the transaction closure would
        // keep that test green. Forcing the transaction's last write to throw
        // and asserting NO broadcast happened is what actually pins "dispatch
        // happens strictly after commit".
        Event::fake([HouseholdChanged::class]);

        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'strategy' => 'delete_contents',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        Event::fake([HouseholdChanged::class]); // reset: the creates + delete above already pinged

        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'inventory_products') && str_starts_with(strtolower(trim($query->sql)), 'update')) {
                throw new \RuntimeException("Simulated failure on the transaction's final write.");
            }
        });

        try {
            $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}");
        } catch (\Throwable) {
            // Whether the simulated failure surfaces as a 500 response or an
            // uncaught exception depends on exception-handling config —
            // irrelevant here. Only the assertions below matter.
        }

        Event::assertNotDispatched(HouseholdChanged::class);
        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
        $this->assertSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_restoring_the_same_batch_twice_the_second_call_is_409(): void
    {
        // M-2: the first call clears deletion_batch_id to null alongside
        // deleted_at, so a batch cannot be replayed. A double-restore must
        // 409 on the second call rather than silently succeed (or error).
        //
        // The 409-on-replay behaviour alone is also produced by deleted_at
        // being cleared (the id-gathering queries all require
        // whereNotNull('deleted_at'), so a re-restored row simply stops
        // matching regardless of its batch id) — so it does not, by itself,
        // pin down the deletion_batch_id clear specifically. The
        // assertDatabaseHas(..., ['deletion_batch_id' => null]) calls below
        // do: they fail if that column is left stamped with the old batch
        // id after a successful restore.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'strategy' => 'delete_products',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertOk();

        $this->assertDatabaseHas('inventory_shelves', ['id' => $shelf->id, 'deletion_batch_id' => null]);
        $this->assertDatabaseHas('inventory_products', ['id' => $product->id, 'deletion_batch_id' => null]);

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertStatus(409);
    }
}

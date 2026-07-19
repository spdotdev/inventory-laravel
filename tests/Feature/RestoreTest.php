<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Events\HouseholdChanged;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Shelf;
use Spdotdev\Inventory\Models\StorageLocation;
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
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'admin']);

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

    public function test_a_member_can_restore_a_batch_they_deleted_themselves(): void
    {
        // Reversed 2026-07-19: a plain Member could soft-delete a product but
        // could not undo their own mistake, since restore used to be gated
        // on `restructure` unconditionally (Owner/Admin only). A Member
        // deletes their own product via the API's ProductController::destroy
        // (no restructure gate on products), then restores that exact batch.
        $user = User::create(['name' => 'Mel', 'email' => 'mel@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'BBBB-2222']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'member']);

        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $deleteResponse = $this->deleteJson("{$this->base}/households/{$household->id}/shelves/{$shelf->id}/products/{$product->id}")
            ->assertOk();
        $batch = $deleteResponse->json('deletion_batch_id');

        $this->postJson("{$this->base}/households/{$household->id}/restore/{$batch}")
            ->assertOk()
            ->assertJsonPath('restored', 1);

        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_a_member_cannot_restore_a_batch_deleted_by_someone_else(): void
    {
        $owner = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'CCCC-3333']);
        $household->users()->attach($owner->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        Sanctum::actingAs($owner);
        $deleteResponse = $this->deleteJson("{$this->base}/households/{$household->id}/shelves/{$shelf->id}/products/{$product->id}")
            ->assertOk();
        $batch = $deleteResponse->json('deletion_batch_id');

        $member = User::create(['name' => 'Mel', 'email' => 'mel@example.test', 'password' => 'secret-password']);
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);
        Sanctum::actingAs($member);

        $this->postJson("{$this->base}/households/{$household->id}/restore/{$batch}")
            ->assertStatus(403);

        $this->assertSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_owner_can_still_restore_a_batch_a_member_deleted(): void
    {
        // Owner/Admin restore ANY batch, unchanged by the batch-ownership fix.
        $owner = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'DDDD-4444']);
        $household->users()->attach($owner->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        $member = User::create(['name' => 'Mel', 'email' => 'mel@example.test', 'password' => 'secret-password']);
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);

        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        Sanctum::actingAs($member);
        $deleteResponse = $this->deleteJson("{$this->base}/households/{$household->id}/shelves/{$shelf->id}/products/{$product->id}")
            ->assertOk();
        $batch = $deleteResponse->json('deletion_batch_id');

        Sanctum::actingAs($owner);
        $this->postJson("{$this->base}/households/{$household->id}/restore/{$batch}")
            ->assertOk()
            ->assertJsonPath('restored', 1);

        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id]);
    }

    public function test_a_member_cannot_restore_a_legacy_batch_with_no_deleted_by(): void
    {
        // Regression: batchOwnerId() used to return null for a batch that
        // pre-dates the deleted_by column (any row soft-deleted before this
        // feature shipped), and RestoreController skipped Gate::authorize
        // entirely whenever batchOwnerId was null — a fail-open that let any
        // Member restore any household's legacy batch. Fixed by gating on
        // Restorer::batchExists() instead, which is true here even though
        // deleted_by is null.
        $owner = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'EEEE-5555']);
        $household->users()->attach($owner->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        $location = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        // Simulate a pre-migration delete: soft-deleted with a batch id but
        // no deleted_by, bypassing the API so deleted_by is never stamped.
        $batch = (string) Str::uuid();
        $product->newQuery()->whereKey($product->getKey())->update([
            'deletion_batch_id' => $batch,
            'deleted_at' => now(),
        ]);

        $member = User::create(['name' => 'Mel', 'email' => 'mel@example.test', 'password' => 'secret-password']);
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);
        Sanctum::actingAs($member);

        $this->postJson("{$this->base}/households/{$household->id}/restore/{$batch}")
            ->assertStatus(403);

        $this->assertSoftDeleted('inventory_products', ['id' => $product->id]);

        Sanctum::actingAs($owner);
        $this->postJson("{$this->base}/households/{$household->id}/restore/{$batch}")
            ->assertOk()
            ->assertJsonPath('restored', 1);
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

    public function test_restoring_a_second_system_shelf_into_a_location_with_a_live_one_is_409(): void
    {
        // C1's second closed door: even with StorageLocation::unsortedShelf()
        // fixed to reuse a trashed row instead of duplicating it, a stale or
        // replayed batch id (an old snackbar, a retried request) could still
        // try to resurrect a DIFFERENT is_system shelf via Undo while one is
        // already live in the same location. Restoring must refuse to
        // recreate the "two live Unsorted shelves in one location" state —
        // nothing downstream (move_contents, search, the retention purge) is
        // designed to reconcile it.
        //
        // The duplicate is planted directly (bypassing unsortedShelf()
        // itself, which now refuses to ever produce this state on its own)
        // to isolate RestoreController's OWN guard from the model-layer fix.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);

        $live = $location->unsortedShelf();

        $stale = $location->shelves()->create(['name' => 'Unsorted', 'is_system' => true, 'position' => 0]);
        $stale->deletion_batch_id = $this->batch;
        $stale->save();
        $stale->delete();

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertStatus(409);

        $this->assertSoftDeleted('inventory_shelves', ['id' => $stale->id]);
        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $live->id]);
        $this->assertSame(
            1,
            Shelf::where('location_id', $location->id)->where('is_system', true)->count(),
            'restoring must never leave two live is_system shelves in one location',
        );
    }

    public function test_undo_of_unsort_products_returns_the_products_to_the_restored_shelf(): void
    {
        // C2: unsort_products MOVES the products onto the location's Unsorted
        // shelf rather than killing them — only the source shelf itself is
        // soft-deleted. Before this fix, restore cleared deleted_at/
        // deletion_batch_id on the shelf ALONE: the shelf came back EMPTY,
        // its products left behind on Unsorted with no link back, while the
        // caller got a 200 and believed Undo had worked.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'strategy' => 'unsort_products',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $unsorted = $location->shelves()->where('is_system', true)->firstOrFail();
        $this->assertDatabaseHas('inventory_products', ['id' => $product->id, 'shelf_id' => $unsorted->id, 'deleted_at' => null]);

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertOk()
            ->assertJsonPath('restored', 2); // the shelf (soft-deleted) + the product (moved)

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertDatabaseHas('inventory_products', [
            'id' => $product->id,
            'shelf_id' => $shelf->id,
            'restore_parent_id' => null,
            'deletion_batch_id' => null,
        ]);
    }

    public function test_undo_of_move_products_returns_them_to_the_restored_shelf(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $target = $location->shelves()->create(['name' => 'Bottom', 'position' => 1]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$shelf->id}", [
            'strategy' => 'move_products',
            'target_shelf_id' => $target->id,
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->assertDatabaseHas('inventory_products', ['id' => $product->id, 'shelf_id' => $target->id, 'deleted_at' => null]);

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertOk()
            ->assertJsonPath('restored', 2); // the shelf (soft-deleted) + the product (moved)

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id]);
        $this->assertDatabaseHas('inventory_products', [
            'id' => $product->id,
            'shelf_id' => $shelf->id,
            'restore_parent_id' => null,
            'deletion_batch_id' => null,
        ]);
    }

    public function test_undo_of_location_move_contents_returns_the_shelves_to_the_restored_location(): void
    {
        $h = $this->memberHousehold();
        $source = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $target = $h->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);
        $shelf = $source->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$source->id}", [
            'strategy' => 'move_contents',
            'target_location_id' => $target->id,
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->assertDatabaseHas('inventory_shelves', ['id' => $shelf->id, 'location_id' => $target->id, 'deleted_at' => null]);

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertOk()
            ->assertJsonPath('restored', 2); // the location (soft-deleted) + the shelf (moved)

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $source->id]);
        $this->assertDatabaseHas('inventory_shelves', [
            'id' => $shelf->id,
            'location_id' => $source->id,
            'restore_parent_id' => null,
            'deletion_batch_id' => null,
        ]);
        // The product never changed identity (it hangs off the shelf) — still
        // on the same shelf, which is back under the restored source location.
        $this->assertDatabaseHas('inventory_products', ['id' => $product->id, 'shelf_id' => $shelf->id, 'deleted_at' => null]);
    }

    public function test_the_delete_undo_delete_purge_sequence_never_permanently_destroys_products(): void
    {
        // C1's full four-step reproduction, verbatim:
        //   1. Delete the (empty) Unsorted shelf — permitted, since empty.
        //   2. Delete a stocked shelf choosing "keep the products"
        //      (unsort_products) — this creates/reuses the Unsorted shelf.
        //   3. Undo step 1.
        //   4. Delete the location itself, moving its contents elsewhere.
        // Before the C1 fix: step 2 minted a SECOND live is_system shelf
        // (unsortedShelf() only ever looked at live shelves), step 3 then
        // resurrected the FIRST one too (RestoreController had no uniqueness
        // check) — two live is_system shelves in one location — and step 4's
        // deleteLocation only ever reparented ONE of them via ->first(),
        // leaving the second (holding the 12 products) live, un-batched, and
        // unreachable under a location this call was about to soft-delete.
        // The retention purge would then force-delete that location and
        // ON DELETE CASCADE would permanently destroy the stranded shelf and
        // every product on it. This test proves the product survives all the
        // way through the purge instead.
        $batchA = '88888888-8888-4888-8888-888888888888';
        $batchB = '99999999-9999-4999-8999-999999999999';
        $batchC = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $target = $h->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);

        // Step 1.
        $unsorted = $location->unsortedShelf();
        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$unsorted->id}", [
            'deletion_batch_id' => $batchA,
        ])->assertOk();

        // Step 2.
        $stocked = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $stocked->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves/{$stocked->id}", [
            'strategy' => 'unsort_products',
            'deletion_batch_id' => $batchB,
        ])->assertOk();

        $this->assertSame(
            1,
            Shelf::withTrashed()->where('location_id', $location->id)->where('is_system', true)->count(),
            'the fix must reuse the trashed Unsorted shelf from step 1, never mint a second one',
        );

        // Step 3: whichever status code this returns, the invariant that
        // actually matters is checked right after — never more than one
        // is_system shelf (live or trashed) for this location.
        $this->postJson("{$this->base}/households/{$h->id}/restore/{$batchA}");

        $this->assertSame(
            1,
            Shelf::withTrashed()->where('location_id', $location->id)->where('is_system', true)->count(),
        );

        // Step 4.
        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'strategy' => 'move_contents',
            'target_location_id' => $target->id,
            'deletion_batch_id' => $batchC,
        ])->assertOk();

        // The product is alive, reachable, and sitting on the TARGET
        // location's own Unsorted shelf — not orphaned under the dead source.
        $product->refresh();
        $this->assertNull($product->deleted_at);
        $targetUnsorted = $target->shelves()->where('is_system', true)->firstOrFail();
        $this->assertSame($targetUnsorted->id, $product->shelf_id);

        // Finally: the retention purge must not cascade-destroy anything
        // still live, even once the old, now-doubly-dead location is old
        // enough to be force-deleted.
        StorageLocation::withTrashed()->whereKey($location->id)->update(['deleted_at' => now()->subDays(31)]);
        Shelf::withTrashed()->where('location_id', $location->id)->update(['deleted_at' => now()->subDays(31)]);
        config()->set('inventory.deleted_retention_days', 30);
        $this->artisan('inventory:deleted:prune')->assertExitCode(0);

        $product->refresh();
        $this->assertNull($product->deleted_at, 'the product must survive the purge of its old, dead location');
        $this->assertDatabaseHas('inventory_products', ['id' => $product->id]);
    }
}

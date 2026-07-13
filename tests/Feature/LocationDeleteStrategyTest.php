<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $shelf = $location->shelves()->firstOrFail();
        $product = $shelf->products()->firstOrFail();

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'deletion_batch_id' => $this->batch,
        ])->assertStatus(422)->assertJsonValidationErrors('strategy');

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
        // Not just the location — genuinely untouched: the stated invariant
        // is "change NOTHING", so the shelf and its product must survive too.
        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id, 'location_id' => $location->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id, 'shelf_id' => $shelf->id]);
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

    public function test_move_contents_merges_the_source_unsorted_shelf_into_the_targets(): void
    {
        // Regression guard for I-1: naively reparenting ALL of the source's
        // shelves (including its is_system Unsorted shelf) into a target that
        // already has its own Unsorted shelf produces two live Unsorted
        // shelves there — exactly the "products split across two Unsorted
        // shelves" state StorageLocation::unsortedShelf()'s lockForUpdate()
        // exists to prevent, reached through a different door (see its
        // docblock). The source's Unsorted shelf must instead be merged away:
        // its products (if any) rescued into the target's Unsorted shelf, and
        // the now-empty source one soft-deleted alongside the rest of the
        // batch — never left dangling under the deleted parent either.
        $h = $this->memberHousehold();
        $source = $h->locations()->create(['name' => 'Fridge', 'type' => StorageType::Fridge]);
        $target = $h->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);

        $sourceUnsorted = $source->unsortedShelf();
        $orphan = $sourceUnsorted->products()->create(['name' => 'Orphan peas', 'quantity' => 1]);
        $targetUnsorted = $target->unsortedShelf();

        // A regular shelf too, to confirm ordinary reparenting still works
        // alongside the Unsorted-shelf merge.
        $regularShelf = $source->shelves()->create(['name' => 'Top', 'position' => 0]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$source->id}", [
            'strategy' => 'move_contents',
            'target_location_id' => $target->id,
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->assertSame(
            1,
            $target->shelves()->where('is_system', true)->count(),
            'the target must end with exactly one live Unsorted shelf',
        );
        $this->assertDatabaseHas('inventory_products', ['id' => $orphan->id, 'shelf_id' => $targetUnsorted->id, 'deleted_at' => null]);
        $this->assertSoftDeleted('inventory_shelves', ['id' => $sourceUnsorted->id, 'deletion_batch_id' => $this->batch]);
        $this->assertDatabaseHas('inventory_shelves', ['id' => $regularShelf->id, 'location_id' => $target->id, 'deleted_at' => null]);
    }

    public function test_move_contents_merges_all_source_unsorted_shelves_into_the_targets(): void
    {
        // C1's third site: gathered as EVERY is_system shelf of the source,
        // not ->first(). Two live is_system shelves in one location shouldn't
        // arise through normal use any more (StorageLocation::unsortedShelf()
        // now refuses to produce that state on its own — see its docblock),
        // but a location could still carry a pre-existing duplicate from
        // before that fix shipped. ->first() would silently strand whichever
        // one it didn't pick: live, un-batched, with its products, under a
        // location this call is about to soft-delete — permanently destroyed
        // the day the retention purge force-deletes the parent and
        // ON DELETE CASCADE fires. See
        // docs/superpowers/sdd/final-review-fixes.md, C1.
        //
        // The duplicate is planted directly (bypassing unsortedShelf()'s own
        // protection) to isolate this call site's own fix.
        $h = $this->memberHousehold();
        $source = $h->locations()->create(['name' => 'Fridge', 'type' => StorageType::Fridge]);
        $target = $h->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);

        $firstUnsorted = $source->shelves()->create(['name' => 'Unsorted', 'is_system' => true, 'position' => 0]);
        $secondUnsorted = $source->shelves()->create(['name' => 'Unsorted', 'is_system' => true, 'position' => 0]);

        // A product on EACH duplicate — so whichever one ->first() happens to
        // pick, the OTHER's product exposes the bug.
        $firstProduct = $firstUnsorted->products()->create(['name' => 'Peas', 'quantity' => 1]);
        $secondProduct = $secondUnsorted->products()->create(['name' => 'Carrots', 'quantity' => 1]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$source->id}", [
            'strategy' => 'move_contents',
            'target_location_id' => $target->id,
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $targetUnsorted = $target->shelves()->where('is_system', true)->firstOrFail();

        $this->assertSame(1, $target->shelves()->where('is_system', true)->count());
        $this->assertDatabaseHas('inventory_products', ['id' => $firstProduct->id, 'shelf_id' => $targetUnsorted->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('inventory_products', ['id' => $secondProduct->id, 'shelf_id' => $targetUnsorted->id, 'deleted_at' => null]);
        $this->assertSoftDeleted('inventory_shelves', ['id' => $firstUnsorted->id, 'deletion_batch_id' => $this->batch]);
        $this->assertSoftDeleted('inventory_shelves', ['id' => $secondUnsorted->id, 'deletion_batch_id' => $this->batch]);
    }

    public function test_move_contents_to_the_location_being_deleted_is_rejected(): void
    {
        // The cross-household half of this guard is pinned by
        // test_move_contents_to_a_location_in_another_household_is_rejected
        // below; this pins the self-target half of the same `||` — nothing
        // else in the suite distinguishes "a foreign location" from "the
        // location being deleted itself" as an invalid target.
        $h = $this->memberHousehold();
        $source = $this->stockedLocation($h);
        $shelf = $source->shelves()->firstOrFail();
        $product = $shelf->products()->firstOrFail();

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$source->id}", [
            'strategy' => 'move_contents',
            'target_location_id' => $source->id,
            'deletion_batch_id' => $this->batch,
        ])->assertStatus(422)->assertJsonValidationErrors('target_location_id');

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $source->id]);
        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id, 'location_id' => $source->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id, 'shelf_id' => $shelf->id]);
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

    public function test_a_rolled_back_delete_never_broadcasts(): void
    {
        // M-1: assertDispatchedTimes(..., 1) above only discriminates on
        // COUNT, not ordering — Task 5 shipped the dispatch INSIDE the
        // transaction closure and that test alone stayed green. Forcing the
        // transaction to fail and asserting NO broadcast happened is what
        // actually pins "dispatch happens strictly after commit".
        //
        // The injected failure fires on the transaction's LAST write (the
        // location's own soft-delete stamp on inventory_storage_locations),
        // not an earlier one — deliberately, so this catches a dispatch call
        // placed ANYWHERE earlier inside the closure, not only one placed as
        // the very first statement. A failure injected on an earlier write
        // would already prevent reaching a later-positioned dispatch call
        // regardless of whether that call sits inside or outside the
        // transaction, so it couldn't actually tell the two apart.
        Event::fake([HouseholdChanged::class]);
        $h = $this->memberHousehold();
        $source = $this->stockedLocation($h);
        $target = $h->locations()->create(['name' => 'Pantry', 'type' => StorageType::Pantry]);

        Event::fake([HouseholdChanged::class]); // reset: the creates above already pinged

        DB::listen(function ($query): void {
            if (str_contains($query->sql, 'inventory_storage_locations') && str_starts_with(strtolower(trim($query->sql)), 'update')) {
                throw new \RuntimeException("Simulated failure on the transaction's final write.");
            }
        });

        try {
            $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$source->id}", [
                'strategy' => 'move_contents',
                'target_location_id' => $target->id,
                'deletion_batch_id' => $this->batch,
            ]);
        } catch (\Throwable) {
            // Whether the simulated failure surfaces as a 500 response or an
            // uncaught exception depends on exception-handling config —
            // irrelevant here. Only the assertions below matter.
        }

        Event::assertNotDispatched(HouseholdChanged::class);
        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $source->id]);
    }
}

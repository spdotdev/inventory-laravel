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
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'admin']);

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

    public function test_a_location_holding_only_an_empty_unsorted_shelf_needs_no_strategy(): void
    {
        // Decision pinned in StorageLocation::shelvesWithContents(): an empty,
        // auto-created Unsorted shelf is disposable and invisible to the
        // user, so its mere existence must not force a delete-strategy
        // prompt — this is exactly the shelf_count == 0 case the Android
        // client relies on (see LocationResource).
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $unsorted = $location->unsortedShelf();

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
        // Left alone — untouched, not part of the batch — exactly like an
        // empty Unsorted shelf orphaned by a failed delete (see
        // HierarchyDeleter's class docblock): harmless, and invisible via
        // Household::shelves()'s soft-delete scoping on the now-dead parent.
        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $unsorted->id]);
    }

    public function test_an_explicit_null_strategy_on_an_empty_location_succeeds(): void
    {
        // Regression guard for the Critical-1 wire-format bug: Android's
        // kotlinx-serialization config (explicitNulls=true, encodeDefaults=false)
        // means a DTO property with no default is ALWAYS encoded, even when
        // it holds null — so a strategy-less delete from the client puts
        // {"strategy":null,"target_location_id":null,...} on the wire, not an
        // omitted key. 'nullable' on both fields must treat that identically
        // to omission when the location has no contents to decide the fate of.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Empty', 'type' => StorageType::Other]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'strategy' => null,
            'target_location_id' => null,
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_an_explicit_null_strategy_on_a_stocked_location_is_still_rejected(): void
    {
        // The other half of the same guard: 'nullable' must NOT let a null
        // strategy slip past Rule::requiredIf when the location actually
        // holds contents — RequiredIf compiles to a plain 'required' rule,
        // which Laravel always evaluates regardless of 'nullable'. The server
        // must still refuse to guess.
        $h = $this->memberHousehold();
        $location = $this->stockedLocation($h);
        $shelf = $location->shelves()->firstOrFail();
        $product = $shelf->products()->firstOrFail();

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'strategy' => null,
            'target_location_id' => null,
            'deletion_batch_id' => $this->batch,
        ])->assertStatus(422)->assertJsonValidationErrors('strategy');

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $shelf->id, 'location_id' => $location->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $product->id, 'shelf_id' => $shelf->id]);
    }

    public function test_a_location_holding_a_stocked_unsorted_shelf_needs_a_strategy(): void
    {
        // The carve-out above is for an EMPTY Unsorted shelf only — one that
        // genuinely holds products is real content, and deleting the
        // location still has to decide that product's fate.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $unsorted = $location->unsortedShelf();
        $unsorted->products()->create(['name' => 'Orphan peas', 'quantity' => 1]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'deletion_batch_id' => $this->batch,
        ])->assertStatus(422)->assertJsonValidationErrors('strategy');

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_an_empty_regular_shelf_still_needs_a_strategy(): void
    {
        // The Unsorted-shelf carve-out is narrow: an ordinary, user-created
        // empty shelf still needs a strategy decision (move it or delete it
        // with the location), unlike the invisible system Unsorted shelf.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $location->shelves()->create(['name' => 'Top', 'position' => 0]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'deletion_batch_id' => $this->batch,
        ])->assertStatus(422)->assertJsonValidationErrors('strategy');
    }

    public function test_a_client_that_trusts_shelf_count_zero_never_gets_a_422(): void
    {
        // This is the whole point of exposing shelf_count on LocationResource:
        // the Android client reads it from the index response and skips the
        // strategy prompt when it's 0. If that ever drifted from
        // DeleteLocationRequest::locationHasContents()'s own rule, a location
        // with only an empty Unsorted shelf would report shelf_count == 0 yet
        // still 422 on a strategy-less delete — silently breaking every such
        // delete on day one. Drive it through the SAME two calls a real
        // client makes: list, read shelf_count, delete accordingly.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $location->unsortedShelf(); // the one case that used to force a strategy

        $shelfCount = $this->getJson("{$this->base}/households/{$h->id}/locations")
            ->assertOk()
            ->json('data.0.shelf_count');

        $this->assertSame(0, $shelfCount, "the client's whole decision hinges on this being 0");

        $payload = ['deletion_batch_id' => $this->batch];
        if ($shelfCount > 0) {
            $payload['strategy'] = 'delete_contents'; // a real client would ask the user here
        }

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", $payload)
            ->assertOk(); // NOT 422 — this is the whole point of the change
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

    public function test_delete_with_a_non_uuid_batch_id_is_rejected(): void
    {
        // deletion_batch_id is optional now, but a value that IS present and
        // non-empty must still be a genuine uuid — a garbage string is still
        // a 422, not silently treated as "absent".
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Empty', 'type' => StorageType::Other]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}", [
            'deletion_batch_id' => 'not-a-uuid',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('deletion_batch_id');

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_bodyless_delete_on_an_empty_location_is_accepted_and_restorable(): void
    {
        // The Android client already shipped to testers (v0.1.8) sends a
        // BODYLESS DELETE — no deletion_batch_id key at all. Rejecting that
        // outright would 422 every location delete on every phone already in
        // the field. The server must instead mint a batch-of-one so the row
        // still lands genuinely restorable via POST .../restore/{batch}, not
        // merely "soft-deleted with a NULL deletion_batch_id" (permanently
        // unreachable through the batch-keyed restore surface).
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Empty', 'type' => StorageType::Other]);

        $response = $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}")
            ->assertOk();

        $batch = $response->json('deletion_batch_id');
        $this->assertIsString($batch);
        $this->assertNotSame('', $batch);

        // The id returned in the response must be the SAME id stamped on the
        // row — this is what pins batchId()'s memoisation: if it minted a
        // fresh uuid on each call, the row's stored id and the response's
        // advertised id would diverge, and this assertion would fail.
        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id, 'deletion_batch_id' => $batch]);

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$batch}")
            ->assertOk()
            ->assertJsonPath('restored', 1);

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_bodyless_delete_on_a_location_whose_shelves_are_all_empty_still_requires_a_strategy(): void
    {
        // The critical nuance: locationHasContents() asks about the
        // location's SHELVES, not its products. A regular (non-system) shelf
        // with zero products on it is still "content" — its own fate as a
        // shelf (move it, or die with the location) still needs deciding.
        // Only an empty system Unsorted shelf is disposable enough to skip
        // the strategy prompt (see test_a_location_holding_only_an_empty_
        // unsorted_shelf_needs_no_strategy above).
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $location->shelves()->create(['name' => 'Top', 'position' => 0]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$location->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('strategy');

        $this->assertNotSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
    }

    public function test_two_location_deletes_in_one_gesture_share_the_client_batch_id(): void
    {
        // Regression guard: if the server ever overrode a client-supplied
        // batch id with a freshly minted one, two deletes sent by a NEW
        // client under the SAME batch id would silently land in two
        // different batches — splitting one Undo gesture (delete these two
        // locations) across two restore calls.
        $h = $this->memberHousehold();
        $first = $h->locations()->create(['name' => 'Empty One', 'type' => StorageType::Other]);
        $second = $h->locations()->create(['name' => 'Empty Two', 'type' => StorageType::Other]);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$first->id}", [
            'deletion_batch_id' => $this->batch,
        ])->assertOk()->assertJsonPath('deletion_batch_id', $this->batch);

        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$second->id}", [
            'deletion_batch_id' => $this->batch,
        ])->assertOk()->assertJsonPath('deletion_batch_id', $this->batch);

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $first->id, 'deletion_batch_id' => $this->batch]);
        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $second->id, 'deletion_batch_id' => $this->batch]);

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertOk()
            ->assertJsonPath('restored', 2);
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

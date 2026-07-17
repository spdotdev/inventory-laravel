<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Events\HouseholdChanged;
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
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'admin']);
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
        // Not just "not soft-deleted" — genuinely untouched: still on its
        // original shelf. A "changed nothing" claim that never checks the
        // foreign key isn't actually pinning "nothing changed".
        $this->assertNotSoftDeleted('inventory_products', ['id' => $p->id, 'shelf_id' => $s->id]);
    }

    public function test_an_empty_shelf_needs_no_strategy(): void
    {
        [$h, $l, $s] = $this->shelfWithProduct();
        Product::query()->delete();

        $this->deleteJson($this->url($h, $l, $s), ['deletion_batch_id' => $this->batch])
            ->assertOk()
            ->assertJsonPath('deletion_batch_id', $this->batch);

        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id, 'deletion_batch_id' => $this->batch]);
    }

    public function test_an_explicit_null_strategy_on_an_empty_shelf_succeeds(): void
    {
        // Regression guard for the Critical-1 wire-format bug: Android's
        // kotlinx-serialization config (explicitNulls=true, encodeDefaults=false)
        // means a DTO property with no default is ALWAYS encoded, even when
        // it holds null — so a strategy-less delete from the client puts
        // {"strategy":null,"target_shelf_id":null,...} on the wire, not an
        // omitted key. 'nullable' on both fields must treat that identically
        // to omission when the shelf has no products to decide the fate of.
        [$h, $l, $s] = $this->shelfWithProduct();
        Product::query()->delete();

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => null,
            'target_shelf_id' => null,
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id, 'deletion_batch_id' => $this->batch]);
    }

    public function test_an_explicit_null_strategy_on_an_occupied_shelf_is_still_rejected(): void
    {
        // The other half of the same guard: 'nullable' must NOT let a null
        // strategy slip past Rule::requiredIf when the shelf actually holds
        // products — RequiredIf compiles to a plain 'required' rule, which
        // Laravel always evaluates regardless of 'nullable'. The server must
        // still refuse to guess.
        [$h, $l, $s, $p] = $this->shelfWithProduct();

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => null,
            'target_shelf_id' => null,
            'deletion_batch_id' => $this->batch,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('strategy');

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $s->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $p->id, 'shelf_id' => $s->id]);
    }

    public function test_move_products_reassigns_them_to_the_target_shelf(): void
    {
        [$h, $l, $s, $p] = $this->shelfWithProduct();
        $target = $l->shelves()->create(['name' => 'Bottom', 'position' => 1]);

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'move_products',
            'target_shelf_id' => $target->id,
            'deletion_batch_id' => $this->batch,
        ])
            ->assertOk()
            ->assertJsonPath('deletion_batch_id', $this->batch);

        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id, 'deletion_batch_id' => $this->batch]);
        $this->assertDatabaseHas('inventory_products', ['id' => $p->id, 'shelf_id' => $target->id, 'deleted_at' => null]);
    }

    public function test_unsort_products_moves_them_to_the_unsorted_shelf(): void
    {
        [$h, $l, $s, $p] = $this->shelfWithProduct();

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'unsort_products',
            'deletion_batch_id' => $this->batch,
        ])
            ->assertOk()
            ->assertJsonPath('deletion_batch_id', $this->batch);

        $unsorted = $l->shelves()->where('is_system', true)->firstOrFail();

        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id, 'deletion_batch_id' => $this->batch]);
        $this->assertDatabaseHas('inventory_products', ['id' => $p->id, 'shelf_id' => $unsorted->id, 'deleted_at' => null]);
    }

    public function test_delete_products_soft_deletes_them_in_the_same_batch(): void
    {
        [$h, $l, $s, $p] = $this->shelfWithProduct();

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'delete_products',
            'deletion_batch_id' => $this->batch,
        ])
            ->assertOk()
            ->assertJsonPath('deletion_batch_id', $this->batch);

        // Same batch id on both rows — that is what lets one Undo bring back the
        // shelf AND its products as a unit.
        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id, 'deletion_batch_id' => $this->batch]);
        $this->assertSoftDeleted('inventory_products', ['id' => $p->id, 'deletion_batch_id' => $this->batch]);
    }

    public function test_move_to_a_shelf_in_another_household_is_rejected(): void
    {
        [$h, $l, $s] = $this->shelfWithProduct();
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
        [$h, $l, $s, $p] = $this->shelfWithProduct();

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'move_products',
            'target_shelf_id' => $s->id,
            'deletion_batch_id' => $this->batch,
        ])->assertStatus(422)->assertJsonValidationErrors('target_shelf_id');

        // A rejected request must leave the world exactly as it found it — the
        // shelf and its product both survive, same as the foreign-household
        // sibling above already checks.
        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $s->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $p->id, 'shelf_id' => $s->id]);
    }

    public function test_delete_with_a_non_uuid_batch_id_is_rejected(): void
    {
        // deletion_batch_id is optional now, but a value that IS present and
        // non-empty must still be a genuine uuid — a garbage string is still
        // a 422, not silently treated as "absent".
        [$h, $l, $s] = $this->shelfWithProduct();
        Product::query()->delete();

        $this->deleteJson($this->url($h, $l, $s), ['deletion_batch_id' => 'not-a-uuid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('deletion_batch_id');

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $s->id]);
    }

    public function test_bodyless_delete_on_an_empty_shelf_is_accepted_and_restorable(): void
    {
        // The Android client already shipped to testers (v0.1.8) sends a
        // BODYLESS DELETE — no deletion_batch_id key at all. Rejecting that
        // outright would 422 every shelf delete on every phone already in the
        // field. The server must instead mint a batch-of-one so the row still
        // lands genuinely restorable via POST .../restore/{batch}, not merely
        // "soft-deleted with a NULL deletion_batch_id" (permanently
        // unreachable through the batch-keyed restore surface).
        [$h, $l, $s] = $this->shelfWithProduct();
        Product::query()->delete();

        $response = $this->deleteJson($this->url($h, $l, $s))->assertOk();

        $batch = $response->json('deletion_batch_id');
        $this->assertIsString($batch);
        $this->assertNotSame('', $batch);

        // The id returned in the response must be the SAME id stamped on the
        // row — this is what pins batchId()'s memoisation: if it minted a
        // fresh uuid on each call, the row's stored id and the response's
        // advertised id would diverge, and this assertion would fail.
        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id, 'deletion_batch_id' => $batch]);

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$batch}")
            ->assertOk()
            ->assertJsonPath('restored', 1);

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $s->id]);
    }

    public function test_bodyless_delete_on_an_occupied_shelf_still_requires_a_strategy(): void
    {
        // The other half of the same fix: making deletion_batch_id optional
        // must NOT relax the strategy requirement. A non-empty shelf still
        // 422s — the server never guesses whether to move, unsort, or
        // destroy its products, batch id or no batch id.
        [$h, $l, $s, $p] = $this->shelfWithProduct();

        $this->deleteJson($this->url($h, $l, $s))
            ->assertStatus(422)
            ->assertJsonValidationErrors('strategy');

        $this->assertNotSoftDeleted('inventory_shelves', ['id' => $s->id]);
        $this->assertNotSoftDeleted('inventory_products', ['id' => $p->id, 'shelf_id' => $s->id]);
    }

    public function test_two_shelf_deletes_in_one_gesture_share_the_client_batch_id(): void
    {
        // Regression guard: if the server ever overrode a client-supplied
        // batch id with a freshly minted one, two deletes sent by a NEW
        // client under the SAME batch id would silently land in two
        // different batches — splitting one Undo gesture (delete these two
        // shelves) across two restore calls.
        [$h, $l, $s] = $this->shelfWithProduct();
        Product::query()->delete();
        $second = $l->shelves()->create(['name' => 'Bottom', 'position' => 1]);

        $this->deleteJson($this->url($h, $l, $s), ['deletion_batch_id' => $this->batch])
            ->assertOk()
            ->assertJsonPath('deletion_batch_id', $this->batch);
        $this->deleteJson($this->url($h, $l, $second), ['deletion_batch_id' => $this->batch])
            ->assertOk()
            ->assertJsonPath('deletion_batch_id', $this->batch);

        $this->assertSoftDeleted('inventory_shelves', ['id' => $s->id, 'deletion_batch_id' => $this->batch]);
        $this->assertSoftDeleted('inventory_shelves', ['id' => $second->id, 'deletion_batch_id' => $this->batch]);

        $this->postJson("{$this->base}/households/{$h->id}/restore/{$this->batch}")
            ->assertOk()
            ->assertJsonPath('restored', 2);
    }

    public function test_delete_broadcasts_to_the_household_exactly_once(): void
    {
        // The BroadcastHouseholdChange observer only fires on Eloquent model
        // events; HierarchyDeleter's writes are all query-builder writes, so
        // the class must dispatch explicitly — exactly once. unsort_products
        // is the strategy that pins this hardest: pre-fix, the lazily-created
        // Unsorted shelf's own `created` event fired a SECOND, premature
        // broadcast from inside the still-open transaction.
        Event::fake([HouseholdChanged::class]);
        [$h, $l, $s] = $this->shelfWithProduct();

        Event::fake([HouseholdChanged::class]); // reset: the creates above already pinged

        $this->deleteJson($this->url($h, $l, $s), [
            'strategy' => 'unsort_products',
            'deletion_batch_id' => $this->batch,
        ])->assertOk();

        Event::assertDispatchedTimes(HouseholdChanged::class, 1);
    }
}

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

class ReorderTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    private function memberHousehold(): Household
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        return $household;
    }

    public function test_locations_can_be_reordered(): void
    {
        $h = $this->memberHousehold();
        $a = $h->locations()->create(['name' => 'Aaa', 'type' => StorageType::Fridge]);
        $b = $h->locations()->create(['name' => 'Bbb', 'type' => StorageType::Pantry]);
        $c = $h->locations()->create(['name' => 'Ccc', 'type' => StorageType::Freezer]);

        $this->patchJson("{$this->base}/households/{$h->id}/locations/reorder", [
            'ids' => [$c->id, $a->id, $b->id],
        ])->assertOk();

        // index() must now honour position, not name — a manual drag is the
        // user's stated order and nothing may silently re-alphabetise it.
        $this->getJson("{$this->base}/households/{$h->id}/locations")
            ->assertOk()
            ->assertJsonPath('data.0.id', $c->id)
            ->assertJsonPath('data.1.id', $a->id)
            ->assertJsonPath('data.2.id', $b->id);
    }

    public function test_shelves_can_be_reordered(): void
    {
        $h = $this->memberHousehold();
        $loc = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $top = $loc->shelves()->create(['name' => 'Top', 'position' => 0]);
        $mid = $loc->shelves()->create(['name' => 'Middle', 'position' => 1]);
        $bot = $loc->shelves()->create(['name' => 'Bottom', 'position' => 2]);

        $this->patchJson("{$this->base}/households/{$h->id}/locations/{$loc->id}/shelves/reorder", [
            'ids' => [$bot->id, $top->id, $mid->id],
        ])->assertOk();

        $this->getJson("{$this->base}/households/{$h->id}/locations/{$loc->id}/shelves")
            ->assertOk()
            ->assertJsonPath('data.0.id', $bot->id)
            ->assertJsonPath('data.1.id', $top->id)
            ->assertJsonPath('data.2.id', $mid->id);
    }

    public function test_reorder_broadcasts_to_the_household(): void
    {
        // The BroadcastHouseholdChange observer only fires on Eloquent model
        // events. A reorder is a query-builder write, which fires NOTHING — so
        // the controller must dispatch explicitly or a second member's list
        // silently goes stale.
        Event::fake([HouseholdChanged::class]);
        $h = $this->memberHousehold();
        $a = $h->locations()->create(['name' => 'Aaa', 'type' => StorageType::Fridge]);
        $b = $h->locations()->create(['name' => 'Bbb', 'type' => StorageType::Pantry]);

        Event::fake([HouseholdChanged::class]); // reset: the creates above already pinged

        $this->patchJson("{$this->base}/households/{$h->id}/locations/reorder", [
            'ids' => [$b->id, $a->id],
        ])->assertOk();

        Event::assertDispatched(
            HouseholdChanged::class,
            fn (HouseholdChanged $e) => $e->householdId === (int) $h->id,
        );
    }

    public function test_reorder_rejects_an_id_from_another_household(): void
    {
        $h = $this->memberHousehold();
        $mine = $h->locations()->create(['name' => 'Mine', 'type' => StorageType::Fridge]);

        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $theirs = $other->locations()->create(['name' => 'Theirs', 'type' => StorageType::Pantry]);

        $this->patchJson("{$this->base}/households/{$h->id}/locations/reorder", [
            'ids' => [$theirs->id, $mine->id],
        ])->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    public function test_reorder_is_all_or_nothing(): void
    {
        // A partial write would leave a half-sorted list, which is worse than no
        // write at all — the user cannot tell which half took.
        //
        // The starting positions MUST be non-zero and distinct, and the bad id
        // must come last. Otherwise a broken implementation that writes as it
        // goes — updating each row before it reaches the invalid one — would
        // never touch $b or $a before failing, and the assertion would pass
        // while the guard did nothing.
        $h = $this->memberHousehold();
        $a = $h->locations()->create(['name' => 'Aaa', 'type' => StorageType::Fridge, 'position' => 5]);
        $b = $h->locations()->create(['name' => 'Bbb', 'type' => StorageType::Pantry, 'position' => 7]);

        $this->patchJson("{$this->base}/households/{$h->id}/locations/reorder", [
            'ids' => [$b->id, $a->id, 99999],
        ])->assertStatus(422);

        // Untouched: had the write run item-by-item before validating, $b would
        // now be at 0 and $a at 1.
        $this->assertDatabaseHas('inventory_storage_locations', ['id' => $a->id, 'position' => 5]);
        $this->assertDatabaseHas('inventory_storage_locations', ['id' => $b->id, 'position' => 7]);
    }

    public function test_reorder_rejects_a_partial_list_of_locations(): void
    {
        // A strict subset is syntactically fine (no foreign ids, no duplicates)
        // but must still be rejected: positions are assigned by array index
        // 0..n-1, so a partial list collapses the omitted rows onto whatever
        // index the submitted ones happen to land on.
        $h = $this->memberHousehold();
        $a = $h->locations()->create(['name' => 'Aaa', 'type' => StorageType::Fridge, 'position' => 0]);
        $b = $h->locations()->create(['name' => 'Bbb', 'type' => StorageType::Pantry, 'position' => 1]);
        $c = $h->locations()->create(['name' => 'Ccc', 'type' => StorageType::Freezer, 'position' => 2]);

        $this->patchJson("{$this->base}/households/{$h->id}/locations/reorder", [
            'ids' => [$c->id, $a->id],
        ])->assertStatus(422)->assertJsonValidationErrors('ids');

        $this->assertDatabaseHas('inventory_storage_locations', ['id' => $a->id, 'position' => 0]);
        $this->assertDatabaseHas('inventory_storage_locations', ['id' => $b->id, 'position' => 1]);
        $this->assertDatabaseHas('inventory_storage_locations', ['id' => $c->id, 'position' => 2]);
    }

    public function test_reorder_rejects_a_partial_list_of_shelves(): void
    {
        $h = $this->memberHousehold();
        $loc = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $top = $loc->shelves()->create(['name' => 'Top', 'position' => 0]);
        $mid = $loc->shelves()->create(['name' => 'Middle', 'position' => 1]);
        $bot = $loc->shelves()->create(['name' => 'Bottom', 'position' => 2]);

        $this->patchJson("{$this->base}/households/{$h->id}/locations/{$loc->id}/shelves/reorder", [
            'ids' => [$bot->id, $top->id],
        ])->assertStatus(422)->assertJsonValidationErrors('ids');

        $this->assertDatabaseHas('inventory_shelves', ['id' => $top->id, 'position' => 0]);
        $this->assertDatabaseHas('inventory_shelves', ['id' => $mid->id, 'position' => 1]);
        $this->assertDatabaseHas('inventory_shelves', ['id' => $bot->id, 'position' => 2]);
    }

    public function test_reorder_rejects_a_soft_deleted_location(): void
    {
        // A trashed location is invisible to $household->locations() (the
        // SoftDeletes global scope excludes it), so it can never be "owned" nor
        // counted toward the total — it must not be reorderable back into a
        // live list.
        $h = $this->memberHousehold();
        $a = $h->locations()->create(['name' => 'Aaa', 'type' => StorageType::Fridge]);
        $b = $h->locations()->create(['name' => 'Bbb', 'type' => StorageType::Pantry]);
        $b->delete();

        $this->patchJson("{$this->base}/households/{$h->id}/locations/reorder", [
            'ids' => [$b->id, $a->id],
        ])->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    public function test_reorder_rejects_a_soft_deleted_shelf(): void
    {
        $h = $this->memberHousehold();
        $loc = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $top = $loc->shelves()->create(['name' => 'Top', 'position' => 0]);
        $bot = $loc->shelves()->create(['name' => 'Bottom', 'position' => 1]);
        $bot->delete();

        $this->patchJson("{$this->base}/households/{$h->id}/locations/{$loc->id}/shelves/reorder", [
            'ids' => [$bot->id, $top->id],
        ])->assertStatus(422)->assertJsonValidationErrors('ids');
    }

    public function test_shelf_reorder_succeeds_when_an_unsorted_shelf_exists_and_is_omitted(): void
    {
        // The Unsorted shelf is never draggable — the Android client only ever
        // sends the real, non-system shelves. The completeness check must not
        // demand the system shelf's id, or every reorder from a location that
        // has ever had an Unsorted shelf created would 422.
        $h = $this->memberHousehold();
        $loc = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $top = $loc->shelves()->create(['name' => 'Top', 'position' => 0]);
        $bot = $loc->shelves()->create(['name' => 'Bottom', 'position' => 1]);
        $unsorted = $loc->unsortedShelf();

        $this->patchJson("{$this->base}/households/{$h->id}/locations/{$loc->id}/shelves/reorder", [
            'ids' => [$bot->id, $top->id],
        ])->assertOk();

        $this->assertDatabaseHas('inventory_shelves', ['id' => $bot->id, 'position' => 0]);
        $this->assertDatabaseHas('inventory_shelves', ['id' => $top->id, 'position' => 1]);
        // Untouched — its position is meaningless, and it was never in the payload.
        $this->assertDatabaseHas('inventory_shelves', ['id' => $unsorted->id, 'position' => 0]);
    }

    public function test_shelf_reorder_rejects_a_payload_containing_the_unsorted_shelf(): void
    {
        // The client should never send the Unsorted shelf's id. If it does
        // (bug, stale cache, tampered request), the server must reject it
        // rather than silently accept a position for a shelf that isn't
        // draggable and always sorts last regardless of what's written here.
        $h = $this->memberHousehold();
        $loc = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $top = $loc->shelves()->create(['name' => 'Top', 'position' => 0]);
        $unsorted = $loc->unsortedShelf();

        $this->patchJson("{$this->base}/households/{$h->id}/locations/{$loc->id}/shelves/reorder", [
            'ids' => [$unsorted->id, $top->id],
        ])->assertStatus(422)->assertJsonValidationErrors('ids');

        $this->assertDatabaseHas('inventory_shelves', ['id' => $top->id, 'position' => 0]);
    }

    public function test_shelf_reorder_broadcasts_to_the_household(): void
    {
        // Same contract as locations: the query-builder write fires no Eloquent
        // events, so the shelf path must dispatch explicitly too.
        Event::fake([HouseholdChanged::class]);
        $h = $this->memberHousehold();
        $loc = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $top = $loc->shelves()->create(['name' => 'Top', 'position' => 0]);
        $bot = $loc->shelves()->create(['name' => 'Bottom', 'position' => 1]);

        Event::fake([HouseholdChanged::class]); // reset: the creates above already pinged

        $this->patchJson("{$this->base}/households/{$h->id}/locations/{$loc->id}/shelves/reorder", [
            'ids' => [$bot->id, $top->id],
        ])->assertOk();

        Event::assertDispatched(
            HouseholdChanged::class,
            fn (HouseholdChanged $e) => $e->householdId === (int) $h->id,
        );
    }
}

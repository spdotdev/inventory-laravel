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
        // must come first. Otherwise a broken implementation that writes as it
        // goes would set position 0 on a row already at 0, and the assertion
        // would pass while the guard did nothing.
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
}

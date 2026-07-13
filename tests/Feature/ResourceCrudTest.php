<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class ResourceCrudTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    /** Authenticate a fresh user and return a household they belong to. */
    private function memberHousehold(string $email = 'stan@example.test', string $code = 'AAAA-1111'): Household
    {
        $user = User::create(['name' => 'Stan', 'email' => $email, 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => $code]);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        return $household;
    }

    public function test_location_crud(): void
    {
        $h = $this->memberHousehold();

        $id = $this->postJson("{$this->base}/households/{$h->id}/locations", ['name' => 'Chest', 'type' => 'freezer'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'freezer')
            ->json('data.id');

        $this->getJson("{$this->base}/households/{$h->id}/locations")->assertOk()->assertJsonCount(1, 'data');
        $this->putJson("{$this->base}/households/{$h->id}/locations/{$id}", ['name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');
        $this->deleteJson("{$this->base}/households/{$h->id}/locations/{$id}")->assertOk();
        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $id]);
    }

    public function test_shelf_and_product_crud(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);

        $shelfId = $this->postJson("{$this->base}/households/{$h->id}/locations/{$location->id}/shelves", ['name' => 'Top'])
            ->assertCreated()->json('data.id');

        $productId = $this->postJson("{$this->base}/households/{$h->id}/shelves/{$shelfId}/products", ['name' => 'Peas', 'quantity' => 2])
            ->assertCreated()->assertJsonPath('data.quantity', 2)->json('data.id');

        $this->getJson("{$this->base}/households/{$h->id}/shelves/{$shelfId}/products")
            ->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("{$this->base}/households/{$h->id}/shelves/{$shelfId}/products/{$productId}")->assertOk();
        $this->assertSoftDeleted('inventory_products', ['id' => $productId]);
    }

    public function test_shelves_created_without_position_get_increasing_order(): void
    {
        // The client sends only `name`; the server must assign an increasing
        // position so the Shelves tab/pager order is deterministic (W5). Without
        // it every shelf lands at 0 and orderBy('position') is undefined.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $url = "{$this->base}/households/{$h->id}/locations/{$location->id}/shelves";

        $first = $this->postJson($url, ['name' => 'Top'])->assertCreated()->json('data.position');
        $second = $this->postJson($url, ['name' => 'Middle'])->assertCreated()->json('data.position');
        $third = $this->postJson($url, ['name' => 'Bottom'])->assertCreated()->json('data.position');

        $this->assertLessThan($second, $first);
        $this->assertLessThan($third, $second);
    }

    public function test_shelf_index_does_not_n_plus_one_on_product_counts(): void
    {
        // ShelfController::index() uses withCount('products') so the per-shelf
        // product tally is folded into the single shelves query via a
        // subselect. If that call is ever dropped, ShelfResource's
        // ->products_count ?? ->products()->count() fallback still returns the
        // right numbers — the suite stays green while index() silently
        // degrades to 1+N queries. Assert query count stays FLAT as shelf
        // count grows, which only holds when withCount is doing its job; an
        // N+1 implementation makes the second measurement scale with N.
        $h = $this->memberHousehold();

        // Build BOTH fixtures before measuring anything — the inserts below
        // must never land inside an enabled query log window, or they inflate
        // the "five shelves" count with unrelated writes and the comparison
        // is meaningless.
        $lonely = $h->locations()->create(['name' => 'One shelf', 'type' => StorageType::Freezer]);
        $lonely->shelves()->create(['name' => 'Solo', 'position' => 0])
            ->products()->create(['name' => 'Peas', 'quantity' => 1]);

        $crowded = $h->locations()->create(['name' => 'Five shelves', 'type' => StorageType::Freezer]);
        foreach (range(1, 5) as $i) {
            $crowded->shelves()->create(['name' => "Shelf {$i}", 'position' => $i])
                ->products()->create(['name' => "Product {$i}", 'quantity' => 1]);
        }

        DB::enableQueryLog();
        $this->getJson("{$this->base}/households/{$h->id}/locations/{$lonely->id}/shelves")->assertOk();
        $queriesForOneShelf = count(DB::getQueryLog());
        DB::flushQueryLog();

        $this->getJson("{$this->base}/households/{$h->id}/locations/{$crowded->id}/shelves")->assertOk();
        $queriesForFiveShelves = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            $queriesForOneShelf,
            $queriesForFiveShelves,
            'Query count must not scale with shelf count — an N+1 fetching each shelf\'s product count separately would grow it.',
        );
    }

    public function test_add_and_remove_floor_quantity_at_zero(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $url = "{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products/{$product->id}";

        $this->postJson("{$url}/add", ['amount' => 3])->assertOk()->assertJsonPath('data.quantity', 5);
        // Partial atomic decrement lands the DB-truth quantity, not just the floor case.
        $this->postJson("{$url}/remove", ['amount' => 2])->assertOk()->assertJsonPath('data.quantity', 3);
        $this->assertDatabaseHas('inventory_products', ['id' => $product->id, 'quantity' => 3]);
        $this->postJson("{$url}/remove", ['amount' => 100])->assertOk()->assertJsonPath('data.quantity', 0);
    }

    public function test_add_clamps_quantity_at_the_cap(): void
    {
        // X5: add() must clamp the resulting total at MAX_QUANTITY so a near-cap
        // quantity plus repeated adds can't exceed the invariant / unsigned ceiling.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 999_999]);

        $url = "{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products/{$product->id}";

        // 999,999 + 1,000 = 1,000,999 → clamped to the 1,000,000 cap.
        $this->postJson("{$url}/add", ['amount' => 1000])
            ->assertOk()->assertJsonPath('data.quantity', 1_000_000);
        // A further add stays pinned at the cap, never beyond.
        $this->postJson("{$url}/add", ['amount' => 5])
            ->assertOk()->assertJsonPath('data.quantity', 1_000_000);
    }

    public function test_stock_amount_over_the_cap_is_rejected(): void
    {
        // W14: an over-cap amount must be a clean 422, not a MySQL unsignedInteger
        // "out of range" 500.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $url = "{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products/{$product->id}";
        $overCap = 1_000_001;

        $this->postJson("{$url}/add", ['amount' => $overCap])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson("{$url}/remove", ['amount' => $overCap])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_stock_amount_must_be_a_positive_integer(): void
    {
        // W15: 0, negative, and missing amounts are rejected on both add and remove
        // (min:1), so a no-op or nonsensical mutation can't slip through.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 5]);

        $url = "{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products/{$product->id}";

        foreach (['add', 'remove'] as $action) {
            $this->postJson("{$url}/{$action}", ['amount' => 0])->assertStatus(422)->assertJsonValidationErrors('amount');
            $this->postJson("{$url}/{$action}", ['amount' => -3])->assertStatus(422)->assertJsonValidationErrors('amount');
            $this->postJson("{$url}/{$action}", [])->assertStatus(422)->assertJsonValidationErrors('amount');
        }
    }

    public function test_move_within_the_household_relocates_the_product(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $from = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $to = $location->shelves()->create(['name' => 'Bottom', 'position' => 1]);
        $product = $from->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->postJson("{$this->base}/households/{$h->id}/shelves/{$from->id}/products/{$product->id}/move", ['shelf_id' => $to->id])
            ->assertOk()->assertJsonPath('data.shelf_id', $to->id);
    }

    public function test_move_to_a_shelf_in_another_household_is_rejected(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        // A shelf belonging to a different household.
        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $otherShelf = $other->locations()->create(['name' => 'X', 'type' => StorageType::Pantry])
            ->shelves()->create(['name' => 'Y', 'position' => 0]);

        $this->postJson("{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products/{$product->id}/move", ['shelf_id' => $otherShelf->id])
            ->assertStatus(422)->assertJsonValidationErrors('shelf_id');
    }

    public function test_cannot_reach_a_location_from_another_household(): void
    {
        $h = $this->memberHousehold();
        // A location in a household the caller is NOT scoped to.
        $other = Household::create(['name' => 'Other', 'join_code' => 'ZZZZ-9999']);
        $foreign = $other->locations()->create(['name' => 'X', 'type' => StorageType::Pantry]);

        // Member of $h, but the location id belongs to $other → scoped binding 404.
        $this->getJson("{$this->base}/households/{$h->id}/locations/{$foreign->id}")->assertNotFound();
    }

    public function test_non_member_cannot_list_locations(): void
    {
        User::create(['name' => 'Out', 'email' => 'out@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs(User::where('email', 'out@example.test')->firstOrFail());
        $foreign = Household::create(['name' => 'Private', 'join_code' => 'ZZZZ-9999']);

        $this->getJson("{$this->base}/households/{$foreign->id}/locations")->assertNotFound();
    }
}

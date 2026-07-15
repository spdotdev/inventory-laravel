<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class SoftDeleteTest extends TestCase
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

    public function test_deleting_a_location_soft_deletes_it(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);

        $location->delete();

        // The row survives — this is the whole point of the change. A mis-tap
        // must be recoverable, and a support-grade restore must always exist.
        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $location->id]);
        $this->assertNotNull(StorageLocation::withTrashed()->find($location->id));
    }

    public function test_a_soft_deleted_location_disappears_from_the_api(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $location->delete();

        $this->getJson("{$this->base}/households/{$h->id}/locations")->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("{$this->base}/households/{$h->id}/locations/{$location->id}")->assertNotFound();
    }

    public function test_products_under_a_soft_deleted_location_are_unreachable(): void
    {
        // Laravel scopes the through-parent's soft deletes automatically
        // (HasOneOrManyThrough::performJoin() adds a SoftDeletableHasManyThrough
        // global scope when the intermediate model — StorageLocation — uses
        // SoftDeletes), so Household::shelves() already excludes a shelf inside a
        // deleted location with no explicit whereNull. This test guards that: if
        // someone later adds withTrashedParents() (which opts back out of that
        // scoping), the product routes would stay live on a fridge the user
        // believes they deleted.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $location->delete();

        $this->getJson("{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products/{$product->id}")
            ->assertNotFound();
    }

    public function test_batch_id_is_persisted_on_a_deleted_row(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);

        $location->deletion_batch_id = '11111111-1111-4111-8111-111111111111';
        $location->save();
        $location->delete();

        $this->assertDatabaseHas('inventory_storage_locations', [
            'id' => $location->id,
            'deletion_batch_id' => '11111111-1111-4111-8111-111111111111',
        ]);
    }

    public function test_deletion_batch_id_is_mass_assignable(): void
    {
        // Pins the `deletion_batch_id` entry in each of the three models'
        // $fillable arrays. test_batch_id_is_persisted_on_a_deleted_row sets it
        // by property assignment, which bypasses $fillable entirely and so would
        // not catch a dropped fillable entry — Model::create() (used here, via
        // each relation's mass-assignment path) would silently drop the value if
        // `deletion_batch_id` were ever removed from $fillable.
        $h = $this->memberHousehold();
        $uuid = '22222222-2222-4222-8222-222222222222';

        $location = $h->locations()->create([
            'name' => 'Chest',
            'type' => StorageType::Freezer,
            'deletion_batch_id' => $uuid,
        ]);
        $shelf = $location->shelves()->create([
            'name' => 'Top',
            'position' => 0,
            'deletion_batch_id' => $uuid,
        ]);
        $product = $shelf->products()->create([
            'name' => 'Peas',
            'quantity' => 2,
            'deletion_batch_id' => $uuid,
        ]);

        $this->assertDatabaseHas('inventory_storage_locations', ['id' => $location->id, 'deletion_batch_id' => $uuid]);
        $this->assertDatabaseHas('inventory_shelves', ['id' => $shelf->id, 'deletion_batch_id' => $uuid]);
        $this->assertDatabaseHas('inventory_products', ['id' => $product->id, 'deletion_batch_id' => $uuid]);
    }

    public function test_deleting_a_solo_product_via_the_api_mints_its_own_batch_id(): void
    {
        // Regression guard for FINDING 1 (Task 6b review): a product deleted
        // on its own — not swept up in a shelf/location delete — used to land
        // with deletion_batch_id = NULL. The only restore surface is
        // batch-keyed (POST .../restore/{batch}), so a NULL batch has no URL
        // and is permanently unrestorable. Every product delete must mint a
        // batch-of-one, and the API must hand it back so a client can offer Undo.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $response = $this->deleteJson("{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products/{$product->id}")
            ->assertOk();

        $batch = $response->json('deletion_batch_id');
        $this->assertIsString($batch);
        $this->assertNotSame('', $batch);

        $this->assertDatabaseHas('inventory_products', [
            'id' => $product->id,
            'deletion_batch_id' => $batch,
        ]);
    }

    public function test_a_solo_deleted_product_does_not_share_a_batch_with_an_unrelated_delete(): void
    {
        // Discriminates against a stub fix (e.g. a fixed/shared placeholder
        // uuid instead of a freshly minted one per delete): two independent
        // solo product deletes must NOT collide on the same batch id, or a
        // future batch-restore would resurrect the wrong product alongside it.
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $peas = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);
        $corn = $shelf->products()->create(['name' => 'Corn', 'quantity' => 1]);

        $this->deleteJson("{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products/{$peas->id}")->assertOk();
        $this->deleteJson("{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products/{$corn->id}")->assertOk();

        $peasBatch = Product::withTrashed()->findOrFail($peas->id)->deletion_batch_id;
        $cornBatch = Product::withTrashed()->findOrFail($corn->id)->deletion_batch_id;

        $this->assertNotNull($peasBatch);
        $this->assertNotNull($cornBatch);
        $this->assertNotSame($peasBatch, $cornBatch);
    }

    public function test_location_products_relation_reaches_through_shelves(): void
    {
        $h = $this->memberHousehold();
        $location = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer]);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);
        $shelf->products()->create(['name' => 'Corn', 'quantity' => 1]);

        // Needed by the location delete strategies: "does this location hold anything?"
        $this->assertSame(2, $location->products()->count());
    }
}

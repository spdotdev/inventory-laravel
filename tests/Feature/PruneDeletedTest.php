<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\StorageLocation;
use Spdotdev\Inventory\Tests\TestCase;

class PruneDeletedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_hard_deletes_rows_past_the_retention_window(): void
    {
        config()->set('inventory.deleted_retention_days', 30);

        $h = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $old = $h->locations()->create(['name' => 'Old', 'type' => StorageType::Freezer]);
        $recent = $h->locations()->create(['name' => 'Recent', 'type' => StorageType::Fridge]);

        $old->delete();
        $recent->delete();

        StorageLocation::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(31)]);

        $this->artisan('inventory:deleted:prune')->assertExitCode(0);

        $this->assertDatabaseMissing('inventory_storage_locations', ['id' => $old->id]);
        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $recent->id]);
    }

    public function test_it_deletes_the_stored_image_of_a_purged_product(): void
    {
        // Soft delete deliberately KEEPS the image (the row is restorable, so the
        // photo must outlive it). That makes the purge the only place the file can
        // ever be reclaimed — without this, every image ever uploaded leaks.
        //
        // image_url is shaped exactly like what ProductController::image() writes
        // (an absolute URL carrying the `inventory/products/` upload-path marker) —
        // a raw path with no marker is not what production stores, and a test using
        // one would hide a marker-recovery bug.
        Storage::fake('public');
        config()->set('inventory.deleted_retention_days', 30);

        $h = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $product = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer])
            ->shelves()->create(['name' => 'Top', 'position' => 0])
            ->products()->create(['name' => 'Peas', 'quantity' => 1, 'image_url' => 'http://inventory.test/storage/inventory/products/peas.jpg']);

        Storage::disk('public')->put('inventory/products/peas.jpg', 'x');
        $product->delete();
        Product::withTrashed()->whereKey($product->id)->update(['deleted_at' => now()->subDays(31)]);

        $this->artisan('inventory:deleted:prune')->assertExitCode(0);

        Storage::disk('public')->assertMissing('inventory/products/peas.jpg');
        $this->assertDatabaseMissing('inventory_products', ['id' => $product->id]);
    }

    public function test_it_purges_a_product_with_an_unmarked_image_url_without_touching_any_file(): void
    {
        // Defensive behaviour, pinned so it can't silently drift again: an
        // image_url with no `inventory/products/` marker was never written by
        // ProductController::image(), so it is not a managed file. The purge
        // must still hard-delete the row, but must NOT treat the arbitrary
        // string as a disk path and delete whatever happens to live there.
        Storage::fake('public');
        config()->set('inventory.deleted_retention_days', 30);

        $h = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $product = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer])
            ->shelves()->create(['name' => 'Top', 'position' => 0])
            ->products()->create(['name' => 'Peas', 'quantity' => 1, 'image_url' => 'products/peas.jpg']);

        Storage::disk('public')->put('products/peas.jpg', 'x');
        $product->delete();
        Product::withTrashed()->whereKey($product->id)->update(['deleted_at' => now()->subDays(31)]);

        $this->artisan('inventory:deleted:prune')->assertExitCode(0);

        Storage::disk('public')->assertExists('products/peas.jpg');
        $this->assertDatabaseMissing('inventory_products', ['id' => $product->id]);
    }

    public function test_retention_of_zero_disables_pruning(): void
    {
        config()->set('inventory.deleted_retention_days', 0);

        $h = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $old = $h->locations()->create(['name' => 'Old', 'type' => StorageType::Freezer]);
        $old->delete();
        StorageLocation::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subYears(5)]);

        $this->artisan('inventory:deleted:prune')->assertExitCode(0);

        $this->assertSoftDeleted('inventory_storage_locations', ['id' => $old->id]);
    }
}

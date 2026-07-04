<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class ProductImageTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    /** A product on a shelf in a household the acting user belongs to. */
    private function memberProduct(string $email = 'stan@example.test', string $code = 'AAAA-1111'): Product
    {
        $user = User::create(['name' => 'Stan', 'email' => $email, 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => $code]);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);
        $shelf = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer])
            ->shelves()->create(['name' => 'Top', 'position' => 0]);

        return $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);
    }

    private function imageUrl(Product $product): string
    {
        return "{$this->base}/households/{$product->shelf->location->household_id}/shelves/{$product->shelf_id}/products/{$product->id}/image";
    }

    public function test_upload_stores_file_and_sets_image_url(): void
    {
        Storage::fake('public');
        $product = $this->memberProduct();

        $this->postJson($this->imageUrl($product), [
            'image' => UploadedFile::fake()->create('photo.jpg', 200, 'image/jpeg'),
        ])->assertOk()->assertJsonPath('data.id', $product->id);

        $product->refresh();
        $this->assertNotNull($product->image_url);
        $this->assertStringContainsString('inventory/products/', $product->image_url);

        // The stored path is recoverable from the URL and exists on the disk.
        $path = substr($product->image_url, strpos($product->image_url, 'inventory/products/'));
        Storage::disk('public')->assertExists($path);
    }

    public function test_replacing_an_image_deletes_the_previous_file(): void
    {
        Storage::fake('public');
        $product = $this->memberProduct();

        $this->postJson($this->imageUrl($product), [
            'image' => UploadedFile::fake()->create('first.png', 100, 'image/png'),
        ])->assertOk();
        $firstUrl = $product->refresh()->image_url;
        $firstPath = substr($firstUrl, strpos($firstUrl, 'inventory/products/'));

        $this->postJson($this->imageUrl($product), [
            'image' => UploadedFile::fake()->create('second.png', 100, 'image/png'),
        ])->assertOk();

        Storage::disk('public')->assertMissing($firstPath);
        $newPath = substr($product->refresh()->image_url, strpos($product->image_url, 'inventory/products/'));
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_deleting_a_product_removes_its_stored_image(): void
    {
        // W15: a direct product delete cleans up its image file (best-effort), so
        // the common delete path doesn't orphan storage. (Cascade deletes via a
        // shelf/location/household are DB-level and intentionally leave the file.)
        Storage::fake('public');
        $product = $this->memberProduct();

        $this->postJson($this->imageUrl($product), [
            'image' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
        ])->assertOk();
        $url = $product->refresh()->image_url;
        $path = substr($url, strpos($url, 'inventory/products/'));
        Storage::disk('public')->assertExists($path);

        $deleteUrl = "{$this->base}/households/{$product->shelf->location->household_id}/shelves/{$product->shelf_id}/products/{$product->id}";
        $this->deleteJson($deleteUrl)->assertOk();

        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseMissing('inventory_products', ['id' => $product->id]);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');
        $product = $this->memberProduct();

        $this->postJson($this->imageUrl($product), [
            'image' => UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_missing_image_part_is_rejected(): void
    {
        Storage::fake('public');
        $product = $this->memberProduct();

        $this->postJson($this->imageUrl($product), [])
            ->assertStatus(422)->assertJsonValidationErrors('image');
    }

    public function test_non_member_cannot_upload(): void
    {
        Storage::fake('public');
        // A product in a household the acting user is NOT a member of.
        $foreign = $this->memberProduct();
        $foreignUrl = $this->imageUrl($foreign);

        // Switch to an outsider.
        $outsider = User::create(['name' => 'Out', 'email' => 'out@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($outsider);

        $this->postJson($foreignUrl, [
            'image' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
        ])->assertNotFound();
    }
}

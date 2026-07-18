<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\Product;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * Web twin of tests/Feature/ProductImageTest.php (API) — web parity T5.
 * WebProductController::image mirrors Api\ProductController::image exactly,
 * so these assertions mirror that suite's coverage: store + image_url,
 * validation failure, tenancy.
 */
class WebProductImageTest extends TestCase
{
    use RefreshDatabase;

    /** A product on a shelf in a household, plus the member user and household. */
    private function memberSetup(): array
    {
        $user = User::create(['name' => 'Web', 'email' => 'webimg@example.test', 'password' => 'secret-password']);
        $household = Household::query()->create(['name' => 'Home', 'join_code' => 'BBBB-2222']);
        $household->users()->attach($user->getKey(), ['joined_at' => now(), 'role' => 'admin']);
        $location = $household->locations()->create(['name' => 'Fridge', 'type' => 'fridge']);
        $shelf = $location->shelves()->create(['name' => 'Top']);
        $product = $shelf->products()->create(['name' => 'Milk', 'quantity' => 1]);

        return [$user, $household, $shelf, $product];
    }

    public function test_upload_stores_file_and_sets_image_url(): void
    {
        Storage::fake('public');
        [$user, $household, $shelf, $product] = $this->memberSetup();

        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.products.image', [$household, $shelf, $product]), [
                'image' => UploadedFile::fake()->create('photo.jpg', 200, 'image/jpeg'),
            ])
            ->assertRedirect(route('inventory.web.products.edit', [$household, $shelf, $product]));

        $product->refresh();
        $this->assertNotNull($product->image_url);
        $this->assertStringContainsString('inventory/products/', $product->image_url);

        $path = substr($product->image_url, strpos($product->image_url, 'inventory/products/'));
        Storage::disk('public')->assertExists($path);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');
        [$user, $household, $shelf, $product] = $this->memberSetup();

        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.products.image', [$household, $shelf, $product]), [
                'image' => UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf'),
            ])
            ->assertSessionHasErrors('image');

        $this->assertNull($product->refresh()->image_url);
    }

    public function test_oversize_upload_is_rejected(): void
    {
        Storage::fake('public');
        [$user, $household, $shelf, $product] = $this->memberSetup();

        $maxKb = (int) config('inventory.image_max_kb', 5120);

        $this->actingAs($user, 'inventory')
            ->post(route('inventory.web.products.image', [$household, $shelf, $product]), [
                'image' => UploadedFile::fake()->create('big.jpg', $maxKb + 100, 'image/jpeg'),
            ])
            ->assertSessionHasErrors('image');

        $this->assertNull($product->refresh()->image_url);
    }

    public function test_non_member_gets_404(): void
    {
        Storage::fake('public');
        [, $household, $shelf, $product] = $this->memberSetup();

        $outsider = User::create(['name' => 'Out', 'email' => 'outimg@example.test', 'password' => 'secret-password']);

        $this->actingAs($outsider, 'inventory')
            ->post(route('inventory.web.products.image', [$household, $shelf, $product]), [
                'image' => UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'),
            ])
            ->assertNotFound();
    }
}

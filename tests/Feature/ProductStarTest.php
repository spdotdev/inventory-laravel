<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class ProductStarTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    public function test_a_product_can_be_starred_and_unstarred(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $h = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $h->users()->attach($user->getKey(), ['joined_at' => now()]);
        $shelf = $h->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer])
            ->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Milk', 'quantity' => 1]);

        $url = "{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products/{$product->id}";

        $this->getJson($url)->assertOk()->assertJsonPath('data.is_starred', false);

        $this->patchJson($url, ['is_starred' => true])->assertOk()->assertJsonPath('data.is_starred', true);
        $this->assertDatabaseHas('inventory_products', ['id' => $product->id, 'is_starred' => true]);

        $this->patchJson($url, ['is_starred' => false])->assertOk()->assertJsonPath('data.is_starred', false);
    }
}

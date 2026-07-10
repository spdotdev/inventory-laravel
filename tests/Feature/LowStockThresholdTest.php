<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/** Phase 2: per-product low-stock threshold (null = feature off for the product). */
class LowStockThresholdTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    /** Authenticate a fresh user and return a shelf in their household. */
    private function memberShelf(): array
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);
        $location = $household->locations()->create(['name' => 'Fridge', 'type' => StorageType::Fridge]);
        $shelf = $location->shelves()->create(['name' => 'Top']);

        return [$household, $shelf];
    }

    public function test_threshold_is_stored_exposed_and_clearable(): void
    {
        [$h, $shelf] = $this->memberShelf();
        $url = "{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products";

        // Create with a threshold — exposed in the resource.
        $id = $this->postJson($url, ['name' => 'Milk', 'quantity' => 5, 'low_stock_threshold' => 2])
            ->assertCreated()
            ->assertJsonPath('data.low_stock_threshold', 2)
            ->json('data.id');

        // Omitting the field on update leaves it untouched (sometimes-rule).
        $this->patchJson("{$url}/{$id}", ['name' => 'Whole milk'])
            ->assertOk()
            ->assertJsonPath('data.low_stock_threshold', 2);

        // Explicit null clears it (feature off).
        $this->patchJson("{$url}/{$id}", ['low_stock_threshold' => null])
            ->assertOk()
            ->assertJsonPath('data.low_stock_threshold', null);
    }

    public function test_products_created_without_threshold_default_to_null(): void
    {
        [$h, $shelf] = $this->memberShelf();

        $this->postJson("{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products", ['name' => 'Peas'])
            ->assertCreated()
            ->assertJsonPath('data.low_stock_threshold', null);
    }

    public function test_zero_and_negative_thresholds_are_rejected(): void
    {
        [$h, $shelf] = $this->memberShelf();
        $url = "{$this->base}/households/{$h->id}/shelves/{$shelf->id}/products";

        // 0 would duplicate the missing-items concept (is_mandatory + qty 0).
        $this->postJson($url, ['name' => 'Milk', 'low_stock_threshold' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('low_stock_threshold');

        $this->postJson($url, ['name' => 'Milk', 'low_stock_threshold' => -3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('low_stock_threshold');
    }
}

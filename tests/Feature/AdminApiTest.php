<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spdotdev\Inventory\Enums\StorageType;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * The admin API is guarded by a static bearer token (EnsureAdminToken), not
 * Sanctum user auth. It's the operator/MCP surface, so its security boundary —
 * disabled when unconfigured, rejects a wrong/absent token — is worth pinning,
 * alongside the destructive cascade-delete it exposes.
 */
class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1/admin';

    private string $token = 'super-secret-admin-token';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('inventory.admin_token', $this->token);
    }

    /** @return array<string, string> */
    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_requests_without_a_token_are_rejected(): void
    {
        $this->getJson("{$this->base}/users")->assertStatus(401);
    }

    public function test_requests_with_a_wrong_token_are_rejected(): void
    {
        $this->getJson("{$this->base}/users", ['Authorization' => 'Bearer nope'])
            ->assertStatus(401);
    }

    public function test_admin_api_is_disabled_when_no_token_is_configured(): void
    {
        config(['inventory.admin_token' => '']);

        // Even a "correct"-looking bearer can't reach a disabled API.
        $this->getJson("{$this->base}/users", $this->auth())->assertStatus(503);
    }

    public function test_valid_token_lists_users(): void
    {
        User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);

        $this->getJson("{$this->base}/users", $this->auth())
            ->assertOk()
            ->assertJsonPath('data.0.email', 'stan@example.test');
    }

    public function test_deleting_a_household_cascades_to_its_tree(): void
    {
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $shelf = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer])
            ->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("{$this->base}/households/{$household->id}", [], $this->auth())->assertOk();

        // ON DELETE CASCADE tears down the whole tree.
        $this->assertDatabaseMissing('inventory_households', ['id' => $household->id]);
        $this->assertDatabaseMissing('inventory_storage_locations', ['household_id' => $household->id]);
        $this->assertDatabaseMissing('inventory_shelves', ['id' => $shelf->id]);
        $this->assertDatabaseMissing('inventory_products', ['id' => $product->id]);
    }

    /**
     * Pre-existing bug: ON DELETE CASCADE drops the product row but has no
     * idea image_url points at a file on disk — left alone, that file leaks
     * forever. ReclaimHouseholdProductImages must run before the cascade.
     */
    public function test_deleting_a_household_reclaims_its_products_stored_images(): void
    {
        Storage::fake('public');
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $shelf = $household->locations()->create(['name' => 'Chest', 'type' => StorageType::Freezer])
            ->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        Storage::disk('public')->put('inventory/products/peas.jpg', 'fake-image-bytes');
        $product->update(['image_url' => 'http://inventory.test/storage/inventory/products/peas.jpg']);
        Storage::disk('public')->assertExists('inventory/products/peas.jpg');

        $this->deleteJson("{$this->base}/households/{$household->id}", [], $this->auth())->assertOk();

        $this->assertDatabaseMissing('inventory_products', ['id' => $product->id]);
        Storage::disk('public')->assertMissing('inventory/products/peas.jpg');
    }

    public function test_deleting_a_user_drops_their_memberships_but_keeps_the_household(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        $this->deleteJson("{$this->base}/users/{$user->id}", [], $this->auth())->assertOk();

        $this->assertDatabaseMissing('inventory_users', ['id' => $user->id]);
        $this->assertDatabaseMissing('inventory_household_user', ['user_id' => $user->id]);
        // The household itself is shared, so it survives losing one member.
        $this->assertDatabaseHas('inventory_households', ['id' => $household->id]);
    }
}

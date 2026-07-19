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

    /**
     * Audit gap: listUsers/listHouseholds ran an unbounded ->get() over the
     * whole table. Capped at 50, same convention as searchUsers.
     */
    public function test_listing_users_is_capped(): void
    {
        for ($i = 0; $i < 55; $i++) {
            User::create(['name' => "U{$i}", 'email' => "u{$i}@example.test", 'password' => 'secret-password']);
        }

        $response = $this->getJson("{$this->base}/users", $this->auth())->assertOk();

        $this->assertCount(50, $response->json('data'));
    }

    public function test_listing_households_is_capped(): void
    {
        for ($i = 0; $i < 55; $i++) {
            Household::create(['name' => "H{$i}", 'join_code' => sprintf('AAAA-%04d', $i)]);
        }

        $response = $this->getJson("{$this->base}/households", $this->auth())->assertOk();

        $this->assertCount(50, $response->json('data'));
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

    /**
     * Audit gap: deleteUser relied solely on cascadeOnDelete and never healed
     * the single-Owner invariant that HouseholdController::leave() enforces
     * (it 409s a sole owner rather than let them leave ownerless). Deleting an
     * Owner through the admin API must promote another member in their place.
     */
    public function test_deleting_the_sole_owner_promotes_another_member(): void
    {
        $owner = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $member = User::create(['name' => 'Alex', 'email' => 'alex@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($owner->getKey(), ['joined_at' => now(), 'role' => 'owner']);
        $household->users()->attach($member->getKey(), ['joined_at' => now(), 'role' => 'member']);

        $this->deleteJson("{$this->base}/users/{$owner->id}", [], $this->auth())->assertOk();

        $this->assertDatabaseMissing('inventory_users', ['id' => $owner->id]);
        $this->assertDatabaseHas('inventory_households', ['id' => $household->id]);
        $this->assertDatabaseHas('inventory_household_user', [
            'household_id' => $household->id,
            'user_id' => $member->id,
            'role' => 'owner',
        ]);
    }

    /**
     * Audit gap (same as above): when the deleted Owner was the household's
     * only member, there's no one left to promote — the household would
     * otherwise survive ownerless and unreachable, exactly the state
     * HouseholdController::leave()'s last-member cleanup exists to prevent.
     */
    public function test_deleting_the_sole_owner_of_a_single_member_household_deletes_it(): void
    {
        $owner = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($owner->getKey(), ['joined_at' => now(), 'role' => 'owner']);

        $this->deleteJson("{$this->base}/users/{$owner->id}", [], $this->auth())->assertOk();

        $this->assertDatabaseMissing('inventory_users', ['id' => $owner->id]);
        $this->assertDatabaseMissing('inventory_households', ['id' => $household->id]);
    }
}

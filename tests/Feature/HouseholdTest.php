<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class HouseholdTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsUser(string $email = 'stan@example.test'): User
    {
        $user = User::create(['name' => 'Stan', 'email' => $email, 'password' => 'secret-password']);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_index_returns_only_my_households(): void
    {
        $user = $this->actingAsUser();
        $mine = Household::create(['name' => 'Mine', 'join_code' => 'AAAA-1111']);
        $mine->users()->attach($user->getKey(), ['joined_at' => now()]);
        Household::create(['name' => 'Theirs', 'join_code' => 'BBBB-2222']);

        $this->getJson('http://inventory.test/api/v1/households')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Mine');
    }

    public function test_store_creates_a_household_with_a_join_code_and_attaches_creator(): void
    {
        $user = $this->actingAsUser();

        $response = $this->postJson('http://inventory.test/api/v1/households', ['name' => 'Garage'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Garage');

        $id = $response->json('data.id');
        $this->assertNotEmpty($response->json('data.join_code'));
        $this->assertDatabaseHas('inventory_household_user', ['household_id' => $id, 'user_id' => $user->getKey()]);
    }

    public function test_join_by_code_adds_the_user(): void
    {
        $this->actingAsUser();
        Household::create(['name' => 'Garage', 'join_code' => 'JOIN-CODE']);

        $this->postJson('http://inventory.test/api/v1/households/join', ['code' => 'JOIN-CODE'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Garage');
    }

    public function test_join_with_an_unknown_code_is_404(): void
    {
        $this->actingAsUser();

        $this->postJson('http://inventory.test/api/v1/households/join', ['code' => 'NOPE-0000'])
            ->assertNotFound();
    }

    public function test_invite_returns_code_and_link(): void
    {
        $user = $this->actingAsUser();
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        $this->getJson("http://inventory.test/api/v1/households/{$household->id}/invite")
            ->assertOk()
            ->assertJsonPath('code', 'AAAA-1111')
            ->assertJsonPath('link', 'https://inventory.test/join/AAAA-1111');
    }

    public function test_invite_link_web_fallback_renders_the_code(): void
    {
        // The link advertised by invite() must resolve to a real page — a
        // recipient opening it in a browser should see the join code, not a 404.
        $this->get('http://inventory.test/join/AAAA-1111')
            ->assertOk()
            ->assertSee('AAAA-1111');
    }

    public function test_leave_detaches_the_user(): void
    {
        $user = $this->actingAsUser();
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);

        $this->deleteJson("http://inventory.test/api/v1/households/{$household->id}/leave")
            ->assertOk();

        $this->assertDatabaseMissing('inventory_household_user', [
            'household_id' => $household->id,
            'user_id' => $user->getKey(),
        ]);
    }

    public function test_last_member_leaving_deletes_the_household_and_its_tree(): void
    {
        // W6: an orphaned (zero-member) household is unreachable by anyone
        // (tenancy 404s non-members) — dead data that only grows. Leaving as the
        // last member must delete it; ON DELETE CASCADE cleans the tree.
        $user = $this->actingAsUser();
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);
        $location = $household->locations()->create(['name' => 'Chest', 'type' => 'freezer']);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        $this->deleteJson("http://inventory.test/api/v1/households/{$household->id}/leave")
            ->assertOk();

        $this->assertDatabaseMissing('inventory_households', ['id' => $household->id]);
        $this->assertDatabaseMissing('inventory_storage_locations', ['id' => $location->id]);
        $this->assertDatabaseMissing('inventory_shelves', ['id' => $shelf->id]);
        $this->assertDatabaseMissing('inventory_products', ['id' => $product->id]);
    }

    /**
     * ON DELETE CASCADE drops the row, but it has no idea image_url points at
     * a file on disk — left alone, that file leaks forever. This is the
     * ReclaimHouseholdProductImages observer's job (see its docblock); it must
     * run BEFORE the cascade, while the tree can still be walked to find it.
     */
    public function test_last_member_leaving_reclaims_the_products_stored_images(): void
    {
        Storage::fake('public');
        $user = $this->actingAsUser();
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);
        $location = $household->locations()->create(['name' => 'Chest', 'type' => 'freezer']);
        $shelf = $location->shelves()->create(['name' => 'Top', 'position' => 0]);
        $product = $shelf->products()->create(['name' => 'Peas', 'quantity' => 2]);

        Storage::disk('public')->put('inventory/products/peas.jpg', 'fake-image-bytes');
        $product->update(['image_url' => 'http://inventory.test/storage/inventory/products/peas.jpg']);
        Storage::disk('public')->assertExists('inventory/products/peas.jpg');

        $this->deleteJson("http://inventory.test/api/v1/households/{$household->id}/leave")
            ->assertOk();

        $this->assertDatabaseMissing('inventory_products', ['id' => $product->id]);
        Storage::disk('public')->assertMissing('inventory/products/peas.jpg');
    }

    public function test_leaving_with_other_members_remaining_keeps_the_household(): void
    {
        $user = $this->actingAsUser();
        $other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'secret-password']);
        $household = Household::create(['name' => 'Garage', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($user->getKey(), ['joined_at' => now()]);
        $household->users()->attach($other->getKey(), ['joined_at' => now()]);

        $this->deleteJson("http://inventory.test/api/v1/households/{$household->id}/leave")
            ->assertOk();

        $this->assertDatabaseHas('inventory_households', ['id' => $household->id]);
        $this->assertDatabaseHas('inventory_household_user', [
            'household_id' => $household->id,
            'user_id' => $other->getKey(),
        ]);
    }

    public function test_non_member_cannot_access_household_routes(): void
    {
        $this->actingAsUser('outsider@example.test');
        $household = Household::create(['name' => 'Private', 'join_code' => 'AAAA-1111']);

        $this->getJson("http://inventory.test/api/v1/households/{$household->id}/invite")
            ->assertNotFound();
    }

    public function test_household_routes_require_authentication(): void
    {
        $this->getJson('http://inventory.test/api/v1/households')->assertUnauthorized();
    }
}

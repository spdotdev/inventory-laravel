<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

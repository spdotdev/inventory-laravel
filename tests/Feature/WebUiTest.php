<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/** Phase-2 web UI: session auth + household onboarding on the inventory domain. */
class WebUiTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test';

    private function user(string $email = 'web@example.test'): User
    {
        return User::create(['name' => 'Web', 'email' => $email, 'password' => bcrypt('secret-password')]);
    }

    public function test_register_creates_account_and_signs_in(): void
    {
        $this->post("{$this->base}/register", [
            'name' => 'Stan',
            'email' => 'stan@example.test',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect();

        $this->assertAuthenticated('inventory');
        $this->assertDatabaseHas('inventory_users', ['email' => 'stan@example.test']);
    }

    public function test_login_with_valid_credentials_reaches_households(): void
    {
        $user = $this->user();

        $this->post("{$this->base}/login", [
            'email' => $user->email,
            'password' => 'secret-password',
        ])->assertRedirect(route('inventory.web.households'));

        $this->assertAuthenticated('inventory');
    }

    public function test_login_with_wrong_password_fails(): void
    {
        $user = $this->user();

        $this->from("{$this->base}/login")->post("{$this->base}/login", [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertRedirect("{$this->base}/login");

        $this->assertGuest('inventory');
    }

    public function test_guests_are_redirected_to_the_sign_in_page(): void
    {
        $this->get("{$this->base}/app/households")
            ->assertRedirect(route('inventory.web.login.show'));
    }

    public function test_household_create_join_and_leave_flow(): void
    {
        $owner = $this->user('owner@example.test');

        $this->actingAs($owner, 'inventory')
            ->post("{$this->base}/app/households", ['name' => 'Home'])
            ->assertRedirect();
        $household = Household::query()->firstOrFail();
        $this->assertTrue($household->users()->whereKey($owner->getKey())->exists());

        // Second user joins by code, sees the household, then leaves.
        $joiner = $this->user('joiner@example.test');
        $this->actingAs($joiner, 'inventory')
            ->post("{$this->base}/app/households/join", ['code' => $household->join_code])
            ->assertRedirect(route('inventory.web.households.show', $household));

        $this->actingAs($joiner, 'inventory')
            ->get(route('inventory.web.households.show', $household))
            ->assertOk()
            ->assertSee('Home')
            ->assertSee($household->join_code);

        $this->actingAs($joiner, 'inventory')
            ->delete(route('inventory.web.households.leave', $household))
            ->assertRedirect(route('inventory.web.households'));
        $this->assertFalse($household->users()->whereKey($joiner->getKey())->exists());
    }

    public function test_non_members_get_404_for_a_household_page(): void
    {
        $owner = $this->user('owner@example.test');
        $household = Household::query()->create(['name' => 'Home', 'join_code' => 'AAAA-1111']);
        $household->users()->attach($owner->getKey(), ['joined_at' => now()]);

        $stranger = $this->user('stranger@example.test');
        $this->actingAs($stranger, 'inventory')
            ->get(route('inventory.web.households.show', $household))
            ->assertNotFound();
    }

    public function test_web_session_does_not_authenticate_the_api(): void
    {
        $user = $this->user();

        // A browser session on the `inventory` guard must not leak into the
        // Sanctum-token-guarded API surface.
        $this->actingAs($user, 'inventory')
            ->getJson("{$this->base}/api/v1/households")
            ->assertUnauthorized();
    }
}

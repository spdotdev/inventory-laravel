<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * Self-service account management (audit gap: no way for an authenticated
 * user to update their own name/email or change their password). Critical
 * paths: happy-path update, email-uniqueness-excluding-self, and the
 * current-password gate on password change.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test/api/v1';

    public function test_user_can_update_own_name_and_email(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/me", [
            'name' => 'Stanley',
            'email' => 'stanley@example.test',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Stanley')
            ->assertJsonPath('data.email', 'stanley@example.test');

        $this->assertDatabaseHas('inventory_users', ['id' => $user->id, 'name' => 'Stanley', 'email' => 'stanley@example.test']);
    }

    public function test_user_can_resubmit_their_own_unchanged_email(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/me", [
            'name' => 'Stan',
            'email' => 'stan@example.test',
        ])->assertOk();
    }

    public function test_user_cannot_take_another_users_email(): void
    {
        User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'secret-password']);
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/me", ['email' => 'other@example.test'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertDatabaseHas('inventory_users', ['id' => $user->id, 'email' => 'stan@example.test']);
    }

    public function test_update_requires_authentication(): void
    {
        $this->patchJson("{$this->base}/me", ['name' => 'Nope'])->assertStatus(401);
    }

    public function test_user_can_change_password_with_correct_current_password(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/me/password", [
            'current_password' => 'secret-password',
            'password' => 'new-secret-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secret-password', $user->fresh()->password));
    }

    public function test_password_change_rejects_wrong_current_password(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);

        $this->patchJson("{$this->base}/me/password", [
            'current_password' => 'wrong-password',
            'password' => 'new-secret-password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check('secret-password', $user->fresh()->password));
    }
}

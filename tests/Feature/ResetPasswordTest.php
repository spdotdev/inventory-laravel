<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * Web password-reset flow. Covers the TTL enforcement that the Carbon 3
 * signed-diff upgrade silently disabled, plus token revocation on success.
 */
class ResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'http://inventory.test/reset-password';

    private function seedUser(string $email = 'stan@example.test', string $password = 'old-password'): User
    {
        return User::create(['name' => 'Stan', 'email' => $email, 'password' => $password]);
    }

    /** Seed a reset row for $email with $rawToken, created $ageMinutes ago. */
    private function seedReset(string $email, string $rawToken, int $ageMinutes = 0): void
    {
        DB::table('inventory_password_resets')->insert([
            'email' => $email,
            'token' => Hash::make($rawToken),
            'created_at' => now()->subMinutes($ageMinutes),
        ]);
    }

    public function test_a_valid_token_resets_the_password_and_revokes_existing_tokens(): void
    {
        $user = $this->seedUser();
        $user->createToken('android'); // an existing session that must be revoked
        $this->seedReset('stan@example.test', 'good-token', ageMinutes: 5);

        $this->from(self::URL)->post(self::URL, [
            'token' => 'good-token',
            'email' => 'stan@example.test',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect('http://inventory.test/reset-password/done'); // PRG: refresh must not re-POST the consumed token

        $this->get('http://inventory.test/reset-password/done')->assertOk();

        $this->assertTrue(Hash::check('new-password-123', $user->refresh()->password));
        // All existing Sanctum tokens revoked, and the reset row consumed.
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('inventory_password_resets', ['email' => 'stan@example.test']);
    }

    public function test_an_expired_token_is_rejected_and_leaves_the_password_unchanged(): void
    {
        $user = $this->seedUser();
        // 61 minutes old — just past the 60-minute TTL.
        $this->seedReset('stan@example.test', 'good-token', ageMinutes: 61);

        $this->from(self::URL)->post(self::URL, [
            'token' => 'good-token',
            'email' => 'stan@example.test',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(self::URL)->assertSessionHasErrors('token');

        $this->assertTrue(Hash::check('old-password', $user->refresh()->password));
        // The stale row is left for the scheduled prune, not consumed by a failed attempt.
        $this->assertDatabaseHas('inventory_password_resets', ['email' => 'stan@example.test']);
    }

    public function test_a_tampered_token_is_rejected(): void
    {
        $user = $this->seedUser();
        $this->seedReset('stan@example.test', 'real-token', ageMinutes: 1);

        $this->from(self::URL)->post(self::URL, [
            'token' => 'wrong-token',
            'email' => 'stan@example.test',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(self::URL)->assertSessionHasErrors('token');

        $this->assertTrue(Hash::check('old-password', $user->refresh()->password));
    }
}

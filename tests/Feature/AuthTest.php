<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Spdotdev\Inventory\Auth\GoogleIdTokenVerifier;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_a_user_and_returns_a_token(): void
    {
        $this->postJson('http://inventory.test/api/v1/auth/register', [
            'name' => 'Stan',
            'email' => 'stan@example.test',
            'password' => 'secret-password',
        ])
            ->assertCreated()
            ->assertJsonStructure(['user' => ['id', 'name', 'email'], 'token'])
            ->assertJsonPath('user.email', 'stan@example.test');

        $this->assertDatabaseHas('inventory_users', ['email' => 'stan@example.test']);
    }

    public function test_register_rejects_a_duplicate_email(): void
    {
        User::create(['name' => 'A', 'email' => 'dup@example.test', 'password' => 'secret-password']);

        $this->postJson('http://inventory.test/api/v1/auth/register', [
            'name' => 'B',
            'email' => 'dup@example.test',
            'password' => 'secret-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_login_succeeds_with_correct_credentials(): void
    {
        User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);

        $this->postJson('http://inventory.test/api/v1/auth/login', [
            'email' => 'stan@example.test',
            'password' => 'secret-password',
        ])->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);

        $this->postJson('http://inventory.test/api/v1/auth/login', [
            'email' => 'stan@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_email_is_case_insensitive_across_register_and_login(): void
    {
        // W13: register with mixed case, then log in with different casing. Both
        // normalize to lowercase at the boundary, so the stored value and the
        // lookup match even on case-sensitive SQLite.
        $this->postJson('http://inventory.test/api/v1/auth/register', [
            'name' => 'Stan',
            'email' => 'Stan.Case@Example.TEST',
            'password' => 'secret-password',
        ])->assertCreated();

        $this->assertDatabaseHas('inventory_users', ['email' => 'stan.case@example.test']);

        $this->postJson('http://inventory.test/api/v1/auth/login', [
            'email' => 'STAN.CASE@example.test',
            'password' => 'secret-password',
        ])->assertOk()->assertJsonStructure(['user', 'token']);
    }

    public function test_login_fails_identically_for_unknown_email(): void
    {
        // W12: an unknown email must produce the same 422 auth.failed as a wrong
        // password (and run the same dummy Hash::check) so login timing can't be
        // used to enumerate registered accounts.
        $this->postJson('http://inventory.test/api/v1/auth/login', [
            'email' => 'ghost@example.test',
            'password' => 'whatever-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_google_only_account_cannot_log_in_with_a_password(): void
    {
        // A passwordless (Google-only) account must be rejected on password login,
        // via the same constant-time path.
        User::create(['name' => 'Goog', 'email' => 'goog@example.test', 'password' => null]);

        $this->postJson('http://inventory.test/api/v1/auth/login', [
            'email' => 'goog@example.test',
            'password' => 'any-password',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        $token = $user->createToken('android')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('http://inventory.test/api/v1/auth/logout')
            ->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_google_creates_then_reuses_the_same_user(): void
    {
        $this->fakeGoogleVerifier([
            'good-token' => ['sub' => 'g-123', 'email' => 'g@example.test', 'name' => 'Goog', 'picture' => null],
        ]);

        $this->postJson('http://inventory.test/api/v1/auth/google', ['id_token' => 'good-token'])
            ->assertOk()
            ->assertJsonPath('user.email', 'g@example.test');
        $this->postJson('http://inventory.test/api/v1/auth/google', ['id_token' => 'good-token'])
            ->assertOk();

        $this->assertDatabaseCount('inventory_users', 1);
        $this->assertDatabaseHas('inventory_users', ['google_id' => 'g-123', 'email' => 'g@example.test']);
    }

    public function test_google_rejects_an_invalid_token(): void
    {
        $this->fakeGoogleVerifier([]); // verifies nothing

        $this->postJson('http://inventory.test/api/v1/auth/google', ['id_token' => 'bad-token'])
            ->assertStatus(401);

        $this->assertDatabaseCount('inventory_users', 0);
    }

    /**
     * Bind a verifier that returns claims for the given token map, null otherwise.
     *
     * @param  array<string, array{sub: string, email: string, name: string|null, picture: string|null}>  $valid
     */
    private function fakeGoogleVerifier(array $valid): void
    {
        $this->app->instance(GoogleIdTokenVerifier::class, new class($valid) implements GoogleIdTokenVerifier
        {
            /** @param array<string, array{sub: string, email: string, name: string|null, picture: string|null}> $valid */
            public function __construct(private array $valid) {}

            public function verify(string $idToken): ?array
            {
                return $this->valid[$idToken] ?? null;
            }
        });
    }
}

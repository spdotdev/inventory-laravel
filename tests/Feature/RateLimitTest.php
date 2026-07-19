<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * Abuse protection on the brute-forceable surfaces: the unauthenticated auth
 * endpoints and join-by-code. Limits are tightened via config at runtime so
 * the 429 boundary is reached deterministically without hundreds of requests.
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic, isolated counters: array cache resets per test-app boot.
        config()->set('cache.default', 'array');
    }

    public function test_auth_endpoints_throttle_per_identity(): void
    {
        // Isolate the per-identity layer.
        config()->set('inventory.rate_limits.auth_per_identity', 3);
        config()->set('inventory.rate_limits.auth_per_ip', 0);

        User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);

        // Three wrong-password attempts are validation failures (422), not 429.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('http://inventory.test/api/v1/auth/login', [
                'email' => 'stan@example.test',
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // The fourth crosses the limit for this email+IP identity.
        $this->postJson('http://inventory.test/api/v1/auth/login', [
            'email' => 'stan@example.test',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    public function test_auth_per_ip_layer_catches_varied_emails(): void
    {
        // Disable the identity layer so only the per-IP cap can fire; vary the
        // email each request so a per-identity limit would never trip.
        config()->set('inventory.rate_limits.auth_per_identity', 0);
        config()->set('inventory.rate_limits.auth_per_ip', 2);

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('http://inventory.test/api/v1/auth/login', [
                'email' => "user{$i}@example.test",
                'password' => 'whatever-password',
            ])->assertStatus(422);
        }

        $this->postJson('http://inventory.test/api/v1/auth/login', [
            'email' => 'user99@example.test',
            'password' => 'whatever-password',
        ])->assertStatus(429);
    }

    public function test_join_by_code_throttles_per_user(): void
    {
        config()->set('inventory.rate_limits.join_per_user', 2);

        $user = User::create(['name' => 'Stan', 'email' => 'stan@example.test', 'password' => 'secret-password']);
        Sanctum::actingAs($user);

        // Two guesses at an unknown code are 404s, not throttled yet.
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('http://inventory.test/api/v1/households/join', ['code' => 'NOPE-0000'])
                ->assertStatus(404);
        }

        $this->postJson('http://inventory.test/api/v1/households/join', ['code' => 'NOPE-0000'])
            ->assertStatus(429);
    }

    public function test_join_throttle_is_per_user_not_global(): void
    {
        config()->set('inventory.rate_limits.join_per_user', 1);

        $alice = User::create(['name' => 'Alice', 'email' => 'alice@example.test', 'password' => 'secret-password']);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.test', 'password' => 'secret-password']);
        Household::create(['name' => 'Garage', 'join_code' => 'JOIN-CODE']);

        Sanctum::actingAs($alice);
        $this->postJson('http://inventory.test/api/v1/households/join', ['code' => 'JOIN-CODE'])->assertOk();
        // Alice's second attempt is throttled...
        $this->postJson('http://inventory.test/api/v1/households/join', ['code' => 'JOIN-CODE'])->assertStatus(429);

        // ...but Bob, a different user, still has his own budget.
        Sanctum::actingAs($bob);
        $this->postJson('http://inventory.test/api/v1/households/join', ['code' => 'JOIN-CODE'])->assertOk();
    }

    public function test_admin_api_throttles_per_ip(): void
    {
        config()->set('inventory.rate_limits.admin_per_ip', 2);
        config()->set('inventory.admin_token', 'super-secret-admin-token');

        $headers = ['Authorization' => 'Bearer super-secret-admin-token'];

        for ($i = 0; $i < 2; $i++) {
            $this->getJson('http://inventory.test/api/v1/admin/users', $headers)->assertOk();
        }

        $this->getJson('http://inventory.test/api/v1/admin/users', $headers)->assertStatus(429);
    }
}

<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spdotdev\Inventory\Models\User;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * Web "Continue with Google" (server-side authorization-code flow). Both Google
 * endpoints are HTTP-faked so the real GoogleTokenInfoVerifier runs — the tests
 * cover the web client id's audience wiring, not just the controller.
 */
class WebGoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    private string $base = 'http://inventory.test';

    private function enableGoogleWeb(): void
    {
        config([
            'inventory.google.web.client_id' => 'web-client-id.test',
            'inventory.google.web.client_secret' => 'web-client-secret',
        ]);
    }

    /**
     * @param  array<string, mixed>  $claimOverrides
     */
    private function fakeGoogle(array $claimOverrides = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['id_token' => 'exchanged-token']),
            'oauth2.googleapis.com/tokeninfo*' => Http::response(array_merge([
                'iss' => 'https://accounts.google.com',
                'aud' => 'web-client-id.test',
                'sub' => 'g-777',
                'email' => 'goog@example.test',
                'email_verified' => 'true',
                'name' => 'Goog Person',
                'picture' => 'https://lh3.example/pic.jpg',
            ], $claimOverrides)),
        ]);
    }

    public function test_routes_404_when_the_web_client_is_not_configured(): void
    {
        $this->get("{$this->base}/auth/google")->assertNotFound();
        $this->get("{$this->base}/auth/google/callback")->assertNotFound();
    }

    public function test_redirects_to_google_with_state_and_registered_redirect_uri(): void
    {
        $this->enableGoogleWeb();

        $response = $this->get("{$this->base}/auth/google");

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', (string) $location);
        $this->assertStringContainsString('client_id=web-client-id.test', (string) $location);
        $this->assertStringContainsString(
            urlencode('https://inventory.test/auth/google/callback'),
            (string) $location,
        );

        $state = session('inventory_google_state');
        $this->assertIsString($state);
        $this->assertStringContainsString('state='.$state, (string) $location);
    }

    public function test_callback_signs_in_and_creates_the_user(): void
    {
        $this->enableGoogleWeb();
        $this->fakeGoogle();

        $this->withSession(['inventory_google_state' => 'expected-state'])
            ->get("{$this->base}/auth/google/callback?code=auth-code&state=expected-state")
            ->assertRedirect(route('inventory.web.households'));

        $this->assertAuthenticated('inventory');
        $this->assertDatabaseHas('inventory_users', [
            'google_id' => 'g-777',
            'email' => 'goog@example.test',
        ]);

        // The exchange must present the code, the secret, and the same
        // redirect_uri that was registered (and sent on the authorize leg).
        Http::assertSent(function ($request) {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['code'] === 'auth-code'
                && $request['client_secret'] === 'web-client-secret'
                && $request['redirect_uri'] === 'https://inventory.test/auth/google/callback';
        });
    }

    public function test_callback_links_google_to_an_existing_password_account(): void
    {
        $this->enableGoogleWeb();
        $this->fakeGoogle(['email' => 'Existing@Example.test']);

        $user = User::create(['name' => 'E', 'email' => 'existing@example.test', 'password' => bcrypt('secret-password')]);

        $this->withSession(['inventory_google_state' => 's'])
            ->get("{$this->base}/auth/google/callback?code=c&state=s")
            ->assertRedirect(route('inventory.web.households'));

        $this->assertAuthenticatedAs($user->fresh(), 'inventory');
        $this->assertDatabaseCount('inventory_users', 1);
        $this->assertDatabaseHas('inventory_users', ['id' => $user->id, 'google_id' => 'g-777']);
    }

    public function test_callback_rejects_a_state_mismatch_without_calling_google(): void
    {
        $this->enableGoogleWeb();
        $this->fakeGoogle();

        $this->withSession(['inventory_google_state' => 'minted'])
            ->get("{$this->base}/auth/google/callback?code=c&state=forged")
            ->assertRedirect(route('inventory.web.login.show'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('inventory');
        Http::assertNothingSent();
    }

    public function test_callback_without_a_prior_redirect_is_rejected(): void
    {
        $this->enableGoogleWeb();
        $this->fakeGoogle();

        // No state in the session at all (direct hit on the callback).
        $this->get("{$this->base}/auth/google/callback?code=c&state=s")
            ->assertRedirect(route('inventory.web.login.show'));

        $this->assertGuest('inventory');
        Http::assertNothingSent();
    }

    public function test_callback_handles_a_denied_consent_gracefully(): void
    {
        $this->enableGoogleWeb();
        $this->fakeGoogle();

        // Google sends error=access_denied and no code when the user cancels.
        $this->withSession(['inventory_google_state' => 's'])
            ->get("{$this->base}/auth/google/callback?error=access_denied&state=s")
            ->assertRedirect(route('inventory.web.login.show'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('inventory');
        Http::assertNothingSent();
    }

    public function test_callback_survives_a_failed_code_exchange(): void
    {
        $this->enableGoogleWeb();
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

        $this->withSession(['inventory_google_state' => 's'])
            ->get("{$this->base}/auth/google/callback?code=bad&state=s")
            ->assertRedirect(route('inventory.web.login.show'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('inventory');
        $this->assertDatabaseCount('inventory_users', 0);
    }

    public function test_callback_rejects_an_id_token_for_a_foreign_audience(): void
    {
        $this->enableGoogleWeb();
        $this->fakeGoogle(['aud' => 'some-other-app.example']);

        $this->withSession(['inventory_google_state' => 's'])
            ->get("{$this->base}/auth/google/callback?code=c&state=s")
            ->assertRedirect(route('inventory.web.login.show'));

        $this->assertGuest('inventory');
        $this->assertDatabaseCount('inventory_users', 0);
    }

    public function test_login_page_shows_the_google_button_only_when_configured(): void
    {
        $this->get("{$this->base}/login")->assertOk()->assertDontSee('Continue with Google');

        $this->enableGoogleWeb();

        $this->get("{$this->base}/login")->assertOk()->assertSee('Continue with Google');
    }
}

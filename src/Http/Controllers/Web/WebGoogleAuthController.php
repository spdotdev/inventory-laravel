<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spdotdev\Inventory\Auth\GoogleAccountLinker;
use Spdotdev\Inventory\Auth\GoogleIdTokenVerifier;

/**
 * Google sign-in for the web UI via the server-side authorization-code flow.
 * The exchanged id_token goes through the same GoogleIdTokenVerifier and
 * account-linking as the API's POST /auth/google, so both surfaces resolve
 * identities identically. Enabled only when the web client id + secret are
 * configured; the routes 404 otherwise (fail closed, like the verifier).
 */
class WebGoogleAuthController extends Controller
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const STATE_SESSION_KEY = 'inventory_google_state';

    public function redirect(Request $request): RedirectResponse
    {
        abort_unless($this->enabled(), 404);

        // Anti-CSRF for the OAuth round trip: the callback only proceeds when
        // Google echoes back the state minted here, in this same session.
        $state = Str::random(40);
        $request->session()->put(self::STATE_SESSION_KEY, $state);

        return redirect()->away(self::AUTH_URL.'?'.http_build_query([
            'client_id' => (string) config('inventory.google.web.client_id'),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
        ]));
    }

    public function callback(Request $request, GoogleIdTokenVerifier $verifier, GoogleAccountLinker $linker): RedirectResponse
    {
        abort_unless($this->enabled(), 404);

        $state = (string) $request->session()->pull(self::STATE_SESSION_KEY);

        if ($state === '' || ! hash_equals($state, (string) $request->query('state'))) {
            return $this->failed();
        }

        $code = (string) $request->query('code');

        if ($code === '') {
            // Also covers Google's error=access_denied (user cancelled consent).
            return $this->failed();
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => (string) config('inventory.google.web.client_id'),
            'client_secret' => (string) config('inventory.google.web.client_secret'),
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri(),
        ]);

        $idToken = $response->successful() ? $response->json('id_token') : null;

        // The id_token from the exchange gets the exact verification the API
        // flow applies to client-supplied tokens (issuer, audience, verified
        // email) — the transport differs, the trust decision must not.
        $claims = is_string($idToken) && $idToken !== '' ? $verifier->verify($idToken) : null;

        if ($claims === null) {
            return $this->failed();
        }

        Auth::guard('inventory')->login($linker->resolve($claims), remember: true);
        $request->session()->regenerate();
        WebAuthController::storePasswordHashInSession($request);

        return redirect()->intended(route('inventory.web.households'));
    }

    private function enabled(): bool
    {
        return (string) config('inventory.google.web.client_id') !== ''
            && (string) config('inventory.google.web.client_secret') !== '';
    }

    /**
     * The registered OAuth redirect URI. Built on the package's own host
     * (config('inventory.domain')), not the host app's APP_URL — the route only
     * exists on the inventory domain (the X3 password-reset lesson), and the
     * value must byte-match the URI registered in the GCP console.
     */
    private function redirectUri(): string
    {
        return 'https://'.config('inventory.domain')
            .route('inventory.web.google.callback', absolute: false);
    }

    private function failed(): RedirectResponse
    {
        return redirect()->route('inventory.web.login.show')
            ->withErrors(['email' => __('Google sign-in failed. Please try again.')]);
    }
}

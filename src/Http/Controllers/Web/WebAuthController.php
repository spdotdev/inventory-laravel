<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spdotdev\Inventory\Models\User;

/**
 * Session-based auth for the Phase-2 web UI, using the `inventory` guard on
 * `inventory_users` — fully separate from the host app's `web` guard and from
 * the API's Sanctum tokens. The same account works in the app and on the web.
 */
class WebAuthController extends Controller
{
    public function showLogin(): View
    {
        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::web.login');
    }

    public function login(Request $request): RedirectResponse
    {
        // Mirror the API's LoginRequest (W13): emails are stored lowercase, and
        // lookups must match on case-sensitive collations (the SQLite test job).
        $request->merge(['email' => Str::lower((string) $request->input('email'))]);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('inventory')->attempt($credentials, remember: true)) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => __('These credentials do not match our records.')]);
        }

        $request->session()->regenerate();
        self::storePasswordHashInSession($request);

        return redirect()->intended(route('inventory.web.households'));
    }

    public function showRegister(): View
    {
        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::web.register');
    }

    public function register(Request $request): RedirectResponse
    {
        // Same normalization as the API's RegisterRequest (W13).
        $request->merge(['email' => Str::lower((string) $request->input('email'))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:inventory_users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        Auth::guard('inventory')->login($user, remember: true);
        $request->session()->regenerate();
        self::storePasswordHashInSession($request);

        return redirect()->route('inventory.web.households');
    }

    /**
     * Refresh the hash AuthenticateSession compares on the /app group.
     * session()->regenerate() keeps session DATA, so signing into a second
     * account in the same browser session would leave the previous account's
     * hash behind — AuthenticateSession would then log the new user straight
     * out (and, without a stored hash refresh, a fresh login after a password
     * reset could be bounced too).
     */
    public static function storePasswordHashInSession(Request $request): void
    {
        $user = Auth::guard('inventory')->user();

        if ($user !== null) {
            $request->session()->put('password_hash_inventory', $user->getAuthPassword());
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('inventory')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('inventory.landing');
    }
}

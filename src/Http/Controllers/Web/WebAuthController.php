<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
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

        return redirect()->intended(route('inventory.web.households'));
    }

    public function showRegister(): View
    {
        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::web.register');
    }

    public function register(Request $request): RedirectResponse
    {
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

        return redirect()->route('inventory.web.households');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('inventory')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('inventory.landing');
    }
}

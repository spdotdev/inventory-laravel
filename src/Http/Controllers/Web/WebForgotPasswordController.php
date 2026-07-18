<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Spdotdev\Inventory\Support\PasswordResetLink;

/**
 * Web entry point for requesting a password-reset email (audit #14) — before
 * this, the reset email could only be triggered from the Android app, leaving
 * a web-only user who forgot their password stranded. Same enumeration-safe
 * posture as the API's ForgotPasswordController: the response never reveals
 * whether the address is registered. Throttled via `inventory-auth` on the
 * route, like login/register.
 */
class WebForgotPasswordController extends Controller
{
    public function show(): View
    {
        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::web.forgot-password');
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        PasswordResetLink::send($data['email']);

        return redirect()->route('inventory.web.forgot-password.show')
            ->with('status', __('If that address is registered you will receive a reset link shortly.'));
    }
}

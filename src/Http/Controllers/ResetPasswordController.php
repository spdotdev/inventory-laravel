<?php

namespace Spdotdev\Inventory\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Spdotdev\Inventory\Models\User;

class ResetPasswordController
{
    private const TOKEN_TTL_MINUTES = 60;

    public function show(Request $request): View
    {
        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::auth.reset-password', [
            'token' => $request->query('token', ''),
            'email' => $request->query('email', ''),
        ]);
    }

    public function update(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $record = DB::table('inventory_password_resets')
            ->where('email', $validated['email'])
            ->first();

        // Explicit expiry check: the token is stale if it was created before the
        // TTL window opened. (Carbon 3's diffInMinutes is signed, so the old
        // `now()->diffInMinutes($created_at) > TTL` was always false for past
        // timestamps — silently disabling expiry. Compare absolute instants instead.)
        $expired = $record !== null
            && Carbon::parse($record->created_at)->isBefore(now()->subMinutes(self::TOKEN_TTL_MINUTES));

        $invalid = $record === null
            || ! Hash::check($validated['token'], $record->token)
            || $expired;

        if ($invalid) {
            return back()->withErrors(['token' => 'This password reset link is invalid or has expired.']);
        }

        $user = User::query()->where('email', $validated['email'])->first();

        if ($user === null) {
            return back()->withErrors(['email' => 'No account found for this email address.']);
        }

        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ])->save();

        // Revoke all existing Sanctum tokens so any stolen bearer token stops working.
        $user->tokens()->delete();

        DB::table('inventory_password_resets')->where('email', $validated['email'])->delete();

        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::auth.reset-password-success');
    }
}

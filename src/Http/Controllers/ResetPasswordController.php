<?php

namespace Spdotdev\Inventory\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;
use Spdotdev\Inventory\Models\User;

class ResetPasswordController
{
    private const TOKEN_TTL_MINUTES = 60;

    public function show(Request $request): View
    {
        return view('inventory::auth.reset-password', [
            'token' => $request->query('token', ''),
            'email' => $request->query('email', ''),
        ]);
    }

    public function update(Request $request): View|RedirectResponse
    {
        $validated = $request->validate([
            'token'                 => ['required', 'string'],
            'email'                 => ['required', 'email'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $record = DB::table('inventory_password_resets')
            ->where('email', $validated['email'])
            ->first();

        $invalid = $record === null
            || ! Hash::check($validated['token'], $record->token)
            || now()->diffInMinutes($record->created_at) > self::TOKEN_TTL_MINUTES;

        if ($invalid) {
            return back()->withErrors(['token' => 'This password reset link is invalid or has expired.']);
        }

        $user = User::query()->where('email', $validated['email'])->first();

        if ($user === null) {
            return back()->withErrors(['email' => 'No account found for this email address.']);
        }

        $user->forceFill(['password' => Hash::make($validated['password'])])->save();

        DB::table('inventory_password_resets')->where('email', $validated['email'])->delete();

        return view('inventory::auth.reset-password-success');
    }
}

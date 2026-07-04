<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Spdotdev\Inventory\Mail\PasswordResetMail;
use Spdotdev\Inventory\Models\User;

class ForgotPasswordController
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $key = 'inventory-password-reset:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['message' => 'Too many requests. Please try again later.'], 429);
        }
        RateLimiter::hit($key, 60);

        // Always return success — never reveal whether the email exists.
        $user = User::query()->where('email', $request->string('email')->lower())->first();

        if ($user !== null) {
            $rawToken = Str::random(64);

            DB::table('inventory_password_resets')->upsert(
                [
                    'email' => $user->email,
                    'token' => Hash::make($rawToken),
                    'created_at' => now(),
                ],
                ['email'],
                ['token', 'created_at'],
            );

            $resetUrl = url(route('inventory.reset-password', [
                'token' => $rawToken,
                'email' => $user->email,
            ], absolute: false));

            Mail::to($user->email)->send(new PasswordResetMail($resetUrl));
        }

        return response()->json(['message' => 'If that address is registered you will receive a reset link shortly.']);
    }
}

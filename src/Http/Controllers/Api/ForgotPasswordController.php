<?php

namespace Spdotdev\Inventory\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Spdotdev\Inventory\Support\PasswordResetLink;

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

        // Always return success — never reveal whether the email exists. The
        // token mint + mail live in the shared PasswordResetLink sender, used
        // by the web forgot-password form too (audit #14).
        PasswordResetLink::send((string) $request->string('email'));

        return response()->json(['message' => 'If that address is registered you will receive a reset link shortly.']);
    }
}

<?php

namespace Spdotdev\Inventory\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spdotdev\Inventory\Mail\PasswordResetMail;
use Spdotdev\Inventory\Models\User;

/**
 * Shared password-reset-link sender for the API's forgot-password endpoint and
 * the web's forgot-password form (audit #14) — one token format, one mail, one
 * "never reveal whether the email exists" posture on both surfaces.
 */
class PasswordResetLink
{
    /** Silently does nothing when no account matches — enumeration-safe. */
    public static function send(string $email): void
    {
        $user = User::query()->where('email', strtolower(trim($email)))->first();

        if ($user === null) {
            return;
        }

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

        // Build the link on the package's own host (config('inventory.domain')),
        // not the host app's APP_URL — `/reset-password` is only registered on the
        // inventory domain, so on a split-domain deploy (INVENTORY_DOMAIN ≠ APP_URL)
        // an APP_URL-based link 404s. Mirror HouseholdController::invite()'s approach.
        $path = route('inventory.reset-password', [
            'token' => $rawToken,
            'email' => $user->email,
        ], absolute: false);

        Mail::to($user->email)->send(new PasswordResetMail('https://'.config('inventory.domain').$path));
    }
}

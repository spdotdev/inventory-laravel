<?php

namespace Spdotdev\Inventory\Auth;

use Illuminate\Support\Str;
use Spdotdev\Inventory\Models\User;

/**
 * Resolves verified Google ID-token claims to a local account. Shared by the
 * API token flow (POST /api/v1/auth/google) and the web redirect flow so both
 * surfaces link identities identically.
 */
class GoogleAccountLinker
{
    /**
     * @param  array{sub: string, email: string, name: string|null, picture: string|null}  $claims
     */
    public function resolve(array $claims): User
    {
        // Normalize the Google email the same way register/login do (W13), so the
        // email fallback links to a case-normalized password account rather than
        // silently creating a duplicate on case-sensitive storage.
        $email = Str::lower($claims['email']);

        // Match by google_id, then fall back to email. The email-match links a
        // Google identity to a pre-existing (e.g. password) account — safe only
        // because the verifier guarantees a Google-verified email bound to our
        // own client ID, so the caller provably controls that address.
        $user = User::query()->where('google_id', $claims['sub'])->first()
            ?? User::query()->where('email', $email)->first()
            ?? new User(['name' => $claims['name'] ?? $email, 'email' => $email]);

        $user->google_id = $claims['sub'];

        if ($claims['picture'] !== null && $user->avatar_url === null) {
            $user->avatar_url = $claims['picture'];
        }

        $user->save();

        return $user;
    }
}

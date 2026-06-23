<?php

namespace Spdotdev\Inventory\Auth;

interface GoogleIdTokenVerifier
{
    /**
     * Verify a Google ID token (JWT) supplied by the Android client.
     *
     * @return array{sub: string, email: string, name: string|null, picture: string|null}|null
     *                                                                                         Verified claims, or null if the token is invalid/untrusted.
     */
    public function verify(string $idToken): ?array;
}

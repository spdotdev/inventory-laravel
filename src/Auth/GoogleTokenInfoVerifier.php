<?php

namespace Spdotdev\Inventory\Auth;

use Illuminate\Support\Facades\Http;

/**
 * Verifies Google ID tokens via Google's tokeninfo endpoint. Simple and
 * dependency-free; right-sized for this app's low volume. Swap the binding for
 * a local JWT-cert verifier if call volume ever makes the round-trip a concern.
 */
class GoogleTokenInfoVerifier implements GoogleIdTokenVerifier
{
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    private const VALID_ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /**
     * @param  list<string>  $allowedClientIds  Accepted `aud` values; empty = skip the audience check.
     */
    public function __construct(private array $allowedClientIds) {}

    public function verify(string $idToken): ?array
    {
        // Fail CLOSED: without configured client IDs we cannot validate the
        // token's audience. Accepting anyway would let any valid Google ID
        // token — including ones minted for a completely different app — sign
        // in here. Refuse rather than trust an unbounded audience.
        if ($this->allowedClientIds === []) {
            return null;
        }

        $response = Http::get(self::TOKENINFO_URL, ['id_token' => $idToken]);

        if ($response->failed()) {
            return null;
        }

        $claims = $response->json();

        if (! is_array($claims) || empty($claims['sub']) || empty($claims['email'])) {
            return null;
        }

        if (! in_array($claims['iss'] ?? '', self::VALID_ISSUERS, true)) {
            return null;
        }

        if (! in_array($claims['aud'] ?? '', $this->allowedClientIds, true)) {
            return null;
        }

        // Require Google to have verified the email. Without this, identity
        // linking by email (in the controller) would be an account-takeover
        // vector — an attacker could present a token carrying someone else's
        // unverified email. tokeninfo returns this as the string "true".
        if (! filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        return [
            'sub' => (string) $claims['sub'],
            'email' => (string) $claims['email'],
            'name' => isset($claims['name']) ? (string) $claims['name'] : null,
            'picture' => isset($claims['picture']) ? (string) $claims['picture'] : null,
        ];
    }
}

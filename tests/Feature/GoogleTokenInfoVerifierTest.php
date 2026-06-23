<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Support\Facades\Http;
use Spdotdev\Inventory\Auth\GoogleTokenInfoVerifier;
use Spdotdev\Inventory\Tests\TestCase;

class GoogleTokenInfoVerifierTest extends TestCase
{
    private const TOKENINFO = 'https://oauth2.googleapis.com/tokeninfo*';

    /** @param array<string, mixed> $overrides */
    private function fakeTokenInfo(array $overrides = []): void
    {
        Http::fake([self::TOKENINFO => Http::response(array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => 'client-123.apps.googleusercontent.com',
            'sub' => 'g-sub-1',
            'email' => 'user@example.test',
            'email_verified' => 'true',
            'name' => 'User',
            'picture' => 'https://pic',
        ], $overrides))]);
    }

    public function test_fails_closed_when_no_client_ids_are_configured(): void
    {
        $this->fakeTokenInfo();
        $verifier = new GoogleTokenInfoVerifier([]);

        $this->assertNull($verifier->verify('any-token'));
        Http::assertNothingSent();
    }

    public function test_accepts_a_valid_token_with_matching_audience_and_verified_email(): void
    {
        $this->fakeTokenInfo();
        $verifier = new GoogleTokenInfoVerifier(['client-123.apps.googleusercontent.com']);

        $claims = $verifier->verify('good-token');

        $this->assertNotNull($claims);
        $this->assertSame('g-sub-1', $claims['sub']);
        $this->assertSame('user@example.test', $claims['email']);
    }

    public function test_rejects_a_mismatched_audience(): void
    {
        $this->fakeTokenInfo(['aud' => 'someone-elses-client']);
        $verifier = new GoogleTokenInfoVerifier(['client-123.apps.googleusercontent.com']);

        $this->assertNull($verifier->verify('token-for-other-app'));
    }

    public function test_rejects_an_unverified_email(): void
    {
        $this->fakeTokenInfo(['email_verified' => 'false']);
        $verifier = new GoogleTokenInfoVerifier(['client-123.apps.googleusercontent.com']);

        $this->assertNull($verifier->verify('unverified-email-token'));
    }

    public function test_rejects_a_wrong_issuer(): void
    {
        $this->fakeTokenInfo(['iss' => 'https://evil.example.com']);
        $verifier = new GoogleTokenInfoVerifier(['client-123.apps.googleusercontent.com']);

        $this->assertNull($verifier->verify('wrong-issuer-token'));
    }

    public function test_rejects_when_tokeninfo_call_fails(): void
    {
        Http::fake([self::TOKENINFO => Http::response('invalid_token', 400)]);
        $verifier = new GoogleTokenInfoVerifier(['client-123.apps.googleusercontent.com']);

        $this->assertNull($verifier->verify('expired-or-bad-token'));
    }
}

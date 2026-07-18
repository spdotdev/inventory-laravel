<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Spdotdev\Inventory\Tests\TestCase;

/**
 * GAP-6 M4: the /app + auth surface was English-only. Locale is negotiated
 * from Accept-Language for the whole inventory domain, not just the landing
 * page — see NegotiateLocale, applied to the routes/web.php domain group.
 */
class WebLocaleTest extends TestCase
{
    private string $base = 'http://inventory.test';

    public function test_login_renders_dutch_for_a_dutch_accept_language(): void
    {
        $this->get("{$this->base}/login", ['Accept-Language' => 'nl-NL,nl;q=0.9,en;q=0.5'])
            ->assertOk()
            ->assertSee('Inloggen')
            ->assertDontSee('Sign in');
    }

    public function test_login_renders_english_by_default(): void
    {
        $this->get("{$this->base}/login")
            ->assertOk()
            ->assertSee('Sign in');
    }
}

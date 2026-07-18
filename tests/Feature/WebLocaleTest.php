<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Spdotdev\Inventory\Http\Controllers\Web\WebLocaleController;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * GAP-6 M4: the /app + auth surface was English-only. Locale is negotiated
 * from Accept-Language for the whole inventory domain, not just the landing
 * page — see NegotiateLocale, applied to the routes/web.php domain group.
 *
 * Web parity T7 adds a visible EN/NL toggle (WebLocaleController) whose
 * cookie wins over Accept-Language, plus NL translations for the web
 * surface's validation errors (lang/nl/validation.php).
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

    public function test_locale_cookie_wins_over_a_conflicting_accept_language(): void
    {
        $this->withCookie(WebLocaleController::COOKIE, 'nl')
            ->get("{$this->base}/login", ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertOk()
            ->assertSee('Inloggen')
            ->assertDontSee('Sign in');
    }

    public function test_posting_a_locale_sets_the_cookie_and_redirects_back(): void
    {
        $response = $this->from("{$this->base}/login")
            ->post("{$this->base}/locale", ['locale' => 'nl']);

        $response->assertRedirect("{$this->base}/login");
        $response->assertCookie(WebLocaleController::COOKIE, 'nl');
    }

    public function test_posting_an_invalid_locale_is_rejected(): void
    {
        $this->from("{$this->base}/login")
            ->post("{$this->base}/locale", ['locale' => 'fr'])
            ->assertSessionHasErrors('locale');
    }

    public function test_the_locale_toggle_persists_across_requests(): void
    {
        $this->post("{$this->base}/locale", ['locale' => 'nl'])
            ->assertCookie(WebLocaleController::COOKIE, 'nl');

        // Cookies set on a response aren't automatically resent by the test
        // client on the next call — simulate the browser carrying it forward.
        $this->withCookie(WebLocaleController::COOKIE, 'nl')
            ->get("{$this->base}/login")
            ->assertSee('Inloggen');
    }

    public function test_a_failed_registration_renders_dutch_validation_messages(): void
    {
        $this->withCookie(WebLocaleController::COOKIE, 'nl')
            ->from("{$this->base}/register")
            ->post("{$this->base}/register", [])
            ->assertSessionHasErrors([
                'name' => 'naam is verplicht.',
                'email' => 'e-mailadres is verplicht.',
                'password' => 'wachtwoord is verplicht.',
            ]);

        // The layout surfaces the first error inline on the redirected-back
        // page — confirm the Dutch message actually renders, not just the
        // session bag.
        $this->withCookie(WebLocaleController::COOKIE, 'nl')
            ->from("{$this->base}/register")
            ->followingRedirects()
            ->post("{$this->base}/register", [])
            ->assertSee('naam is verplicht.');
    }
}

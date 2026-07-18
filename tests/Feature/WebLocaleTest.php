<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Translation\FileLoader;
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

    /**
     * Program-review finding 3: InventoryServiceProvider::boot() registers
     * lang/ in the default namespace via addPath() so lang/nl/validation.php
     * is reachable by the framework's own `validation.*` lookups (see the
     * test above). This pins down WHY that test's message wins for a shared
     * key: Illuminate\Translation\FileLoader::loadPaths() reduces over the
     * loader's registered paths in order and merges each path's file on top
     * of the previous with array_replace_recursive() — so the LAST
     * registered path wins for any key both define. Laravel's own
     * TranslationServiceProvider registers the host's default lang path
     * during the register() phase, before any package's boot() runs, so the
     * package's addPath() call here always lands after it and therefore
     * wins for shared default-namespace keys. This is the corrected,
     * accurate account of that precedence — see the (previously backwards)
     * docblock at the addPath() call site in InventoryServiceProvider.
     */
    public function test_the_packages_lang_path_is_registered_after_the_hosts_default_path(): void
    {
        // loadTranslationsFrom() defers its addPath() call via
        // callAfterResolving('translator', ...) — it only fires once
        // something actually resolves the `translator` abstract (as every
        // real request does). Resolve it here before inspecting the loader,
        // or the package's path won't have been added yet.
        $this->app->make('translator');

        /** @var FileLoader $loader */
        $loader = $this->app->make('translation.loader');
        $paths = $loader->paths();

        $this->assertNotEmpty($paths, 'Expected at least the host default lang path to be registered.');
        $this->assertSame(
            realpath(__DIR__.'/../../lang'),
            realpath($paths[count($paths) - 1]),
            'The package lang/ path must be the LAST registered path so it wins over any host-defined '
            .'default-namespace translations for shared keys (see FileLoader::loadPaths()).'
        );
    }
}

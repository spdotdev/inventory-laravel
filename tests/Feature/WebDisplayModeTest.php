<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Spdotdev\Inventory\Http\Controllers\Web\WebDisplayModeController;
use Spdotdev\Inventory\Tests\TestCase;

/**
 * Web parity T6: light/dark toggle. The cookie is read server-side by the
 * layout and stamped onto <html data-theme="..."> so there is never a flash
 * of the wrong palette — this asserts that stamping, plus that the light and
 * dark token blocks in the layout's CSS define the same set of custom
 * properties (a divergence there would mean some component only has a color
 * in one palette).
 */
class WebDisplayModeTest extends TestCase
{
    private string $base = 'http://inventory.test';

    public function test_no_cookie_renders_without_a_data_theme_attribute(): void
    {
        $this->get("{$this->base}/login")
            ->assertOk()
            ->assertSee('<html lang="en">', false);
    }

    public function test_light_cookie_stamps_the_html_tag(): void
    {
        $this->withCookie(WebDisplayModeController::COOKIE, 'light')
            ->get("{$this->base}/login")
            ->assertOk()
            ->assertSee('<html lang="en" data-theme="light">', false);
    }

    public function test_dark_cookie_stamps_the_html_tag(): void
    {
        $this->withCookie(WebDisplayModeController::COOKIE, 'dark')
            ->get("{$this->base}/login")
            ->assertOk()
            ->assertSee('<html lang="en" data-theme="dark">', false);
    }

    public function test_an_invalid_cookie_value_is_normalized_to_dark(): void
    {
        $this->withCookie(WebDisplayModeController::COOKIE, 'nonsense')
            ->get("{$this->base}/login")
            ->assertOk()
            ->assertSee('<html lang="en" data-theme="dark">', false);
    }

    public function test_posting_a_mode_sets_the_cookie_and_redirects_back(): void
    {
        $response = $this->from("{$this->base}/login")
            ->post("{$this->base}/display-mode", ['mode' => 'light']);

        $response->assertRedirect("{$this->base}/login");
        $response->assertCookie(WebDisplayModeController::COOKIE, 'light');
    }

    public function test_posting_an_invalid_mode_is_rejected(): void
    {
        $this->from("{$this->base}/login")
            ->post("{$this->base}/display-mode", ['mode' => 'purple'])
            ->assertSessionHasErrors('mode');
    }

    /**
     * Both palettes must define exactly the same custom-property names — a
     * component styled only via one block's token would silently render
     * unstyled (falling back to inherited/default) under the other palette.
     */
    public function test_light_and_dark_token_blocks_define_the_same_properties(): void
    {
        $css = file_get_contents(__DIR__.'/../../resources/views/web/layout.blade.php');
        $this->assertIsString($css);

        preg_match_all('/--([a-z-]+):/', $this->between($css, ':root[data-theme="dark"]{', '}'), $darkMatches);
        preg_match_all('/--([a-z-]+):/', $this->between($css, ':root[data-theme="light"]{', '}'), $lightMatches);

        $darkTokens = collect($darkMatches[1])->unique()->sort()->values();
        $lightTokens = collect($lightMatches[1])->unique()->sort()->values();

        $this->assertNotEmpty($darkTokens);
        $this->assertSame($darkTokens->all(), $lightTokens->all());
    }

    private function between(string $haystack, string $start, string $end): string
    {
        $startPos = strpos($haystack, $start);
        $this->assertNotFalse($startPos, "Could not find '{$start}' in layout.blade.php");
        $from = $startPos + strlen($start);
        $endPos = strpos($haystack, $end, $from);
        $this->assertNotFalse($endPos);

        return substr($haystack, $from, $endPos - $from);
    }
}

<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Spdotdev\Inventory\Tests\TestCase;

/**
 * Web parity Task 1: Alpine.js is vendored (no CDN) and published like other
 * package public assets; the shared feedback layer (savebar/toast partials +
 * web-feedback.js) is wired into the layout as pure plumbing — no existing
 * non-JS page should change behavior because of it.
 */
class AlpineFoundationTest extends TestCase
{
    public function test_vendored_alpine_asset_is_present_and_pinned(): void
    {
        $path = __DIR__.'/../../public/js/alpine.min.js';

        $this->assertFileExists($path);
        $this->assertNotEmpty(file_get_contents($path));

        // Pinned version 3.15.12 — see public/js/README.md for provenance.
        $this->assertSame(
            '57b37d7cae9a27d965fdae4adcc844245dfdc407e655aee85dcfff3a08036a3f',
            hash_file('sha256', $path)
        );
    }

    public function test_shared_feedback_script_is_present(): void
    {
        $path = __DIR__.'/../../public/js/web-feedback.js';

        $this->assertFileExists($path);
        $this->assertStringContainsString('InventoryFeedback', (string) file_get_contents($path));
    }

    public function test_login_page_renders_without_any_js_dependency(): void
    {
        // A JS-free page (login form) still renders correctly: Alpine/the
        // feedback layer are additive <script defer> plumbing only, never a
        // hard rendering dependency.
        $this->get('http://inventory.test/login')
            ->assertOk()
            ->assertSee('Sign in')
            ->assertDontSee('window.Alpine', false);
    }

    public function test_layout_loads_alpine_and_feedback_assets_with_defer(): void
    {
        $response = $this->get('http://inventory.test/login');

        $response->assertOk();
        $response->assertSee('vendor/inventory/js/alpine.min.js', false);
        $response->assertSee('vendor/inventory/js/web-feedback.js', false);
        $response->assertSee('inv-savebar', false);
        $response->assertSee('inv-toast-container', false);
    }
}

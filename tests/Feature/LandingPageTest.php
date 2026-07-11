<?php

namespace Spdotdev\Inventory\Tests\Feature;

use Illuminate\Support\Facades\Lang;
use Spdotdev\Inventory\Tests\TestCase;

class LandingPageTest extends TestCase
{
    public function test_landing_strings_resolve_in_english_and_dutch(): void
    {
        $this->assertSame('Know what you have,', Lang::get('inventory::landing.hero_title_top', [], 'en'));
        $this->assertSame('Weet wat je in huis hebt,', Lang::get('inventory::landing.hero_title_top', [], 'nl'));
    }

    public function test_en_and_nl_landing_locales_are_in_lockstep(): void
    {
        $en = require __DIR__.'/../../lang/en/landing.php';
        $nl = require __DIR__.'/../../lang/nl/landing.php';

        $this->assertSame(array_keys($en), array_keys($nl));
    }

    public function test_mockup_partials_render(): void
    {
        $dashboard = view('inventory::landing._mock-dashboard')->render();
        $location = view('inventory::landing._mock-location')->render();

        $this->assertStringContainsString('Running low', $dashboard);
        $this->assertStringContainsString('Top shelf', $location);
    }

    public function test_landing_renders_the_marketing_page_in_english_by_default(): void
    {
        $this->get('http://inventory.test/')
            ->assertOk()
            ->assertSee('Know what you have,')
            ->assertSee('Create a free account')
            ->assertSee('private preview')
            ->assertDontSee('Check back soon');
    }

    public function test_landing_renders_dutch_for_a_dutch_accept_language(): void
    {
        $this->get('http://inventory.test/', ['Accept-Language' => 'nl-NL,nl;q=0.9,en;q=0.5'])
            ->assertOk()
            ->assertSee('Weet wat je in huis hebt,');
    }

    public function test_landing_falls_back_to_english_for_an_unsupported_locale(): void
    {
        $this->get('http://inventory.test/', ['Accept-Language' => 'de-DE,de;q=0.9'])
            ->assertOk()
            ->assertSee('Know what you have,');
    }
}

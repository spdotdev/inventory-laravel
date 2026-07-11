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
}

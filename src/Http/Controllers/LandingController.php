<?php

namespace Spdotdev\Inventory\Http\Controllers;

use Illuminate\View\View;

class LandingController
{
    public function index(): View
    {
        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::landing');
    }
}

<?php

namespace Spdotdev\Inventory\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class LandingController
{
    public function index(Request $request): View
    {
        // Landing page only: the marketing copy is EN + NL, negotiated from the
        // browser. The web app UI deliberately stays English, so the locale is
        // set here per-request rather than in middleware.
        App::setLocale($request->getPreferredLanguage(['en', 'nl']) ?? 'en');

        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::landing');
    }
}

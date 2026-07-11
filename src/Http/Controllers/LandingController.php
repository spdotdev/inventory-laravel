<?php

namespace Spdotdev\Inventory\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;

class LandingController
{
    public function index(Request $request): Response
    {
        // Landing page only: the marketing copy is EN + NL, negotiated from the
        // browser. The web app UI deliberately stays English, so the locale is
        // set here per-request rather than in middleware.
        App::setLocale($request->getPreferredLanguage(['en', 'nl']) ?? 'en');

        // Vary: Accept-Language so proxies/CDNs cache the EN and NL bodies separately
        // instead of serving one negotiated variant to every locale.
        return response()->view('inventory::landing')->header('Vary', 'Accept-Language');
    }
}

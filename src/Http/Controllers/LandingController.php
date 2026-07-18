<?php

namespace Spdotdev\Inventory\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LandingController
{
    public function index(Request $request): Response
    {
        // Locale is negotiated for the whole inventory domain (landing, auth,
        // /app pages) by the inventory.locale middleware — see routes/web.php.
        return response()->view('inventory::landing');
    }
}

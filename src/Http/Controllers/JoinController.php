<?php

namespace Spdotdev\Inventory\Http\Controllers;

use Illuminate\View\View;

class JoinController
{
    /**
     * Web fallback for the invite link advertised by HouseholdController::invite
     * (`https://{domain}/join/{code}`). A recipient who opens the link in a
     * browser instead of the app lands here — show the code and point them at
     * the app, rather than a hard 404 at the exact moment onboarding matters.
     */
    public function show(string $code): View
    {
        // @phpstan-ignore argument.type (the inventory:: namespace is registered at runtime via loadViewsFrom, so it is not resolvable during package-only static analysis)
        return view('inventory::join', [
            'code' => $code,
            'appUrl' => (string) config('inventory.android_app_url', ''),
        ]);
    }
}

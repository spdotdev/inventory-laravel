<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Web parity T6: manual light/dark toggle for the layout's CSS custom
 * properties, persisted in a cookie the layout reads server-side to stamp
 * `<html data-theme>` on first paint (no flash of the wrong palette).
 *
 * Deliberately named "display mode" (cookie/route/class) rather than "theme"
 * — that word is already taken by the per-household color/icon "Appearance"
 * card (see HouseholdThemeTest / UpdateHouseholdRequest). Two unrelated
 * concepts, two names.
 *
 * A plain form POST + redirect-back, not an Alpine/fetch toggle: it works
 * identically with JS on or off, needs no client-side cookie parsing, and a
 * full response round-trip is cheap for something toggled once in a while —
 * unlike the Task 1 optimistic-mutation surfaces, there is no "in flight"
 * state worth showing here.
 */
class WebDisplayModeController extends Controller
{
    public const COOKIE = 'inv_display_mode';

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:light,dark']]);

        return redirect()->back()
            ->withCookie(cookie(self::COOKIE, $data['mode'], 60 * 24 * 365));
    }
}

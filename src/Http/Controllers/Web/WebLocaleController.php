<?php

namespace Spdotdev\Inventory\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Web parity T7: visible EN/NL switch, persisted in a cookie that
 * NegotiateLocale prefers over the Accept-Language header on every
 * subsequent request. A plain form POST + redirect-back — same reasoning as
 * WebDisplayModeController: this is a rare, deliberate toggle, not an
 * optimistic Task 1 mutation.
 */
class WebLocaleController extends Controller
{
    public const COOKIE = 'inv_locale';

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'in:en,nl']]);

        return redirect()->back()
            ->withCookie(cookie(self::COOKIE, $data['locale'], 60 * 24 * 365));
    }
}

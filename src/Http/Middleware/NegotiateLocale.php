<?php

namespace Spdotdev\Inventory\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Spdotdev\Inventory\Http\Controllers\Web\WebLocaleController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Negotiates EN/NL from the browser's Accept-Language header for every page on
 * the inventory domain — landing, auth (login/register) and the session-guarded
 * /app pages alike, so a Dutch household sees the whole surface in Dutch, not
 * just the marketing page (GAP-6 M4). Mirrors the landing page's original
 * per-request negotiation, now centralized so every route gets it consistently.
 *
 * Web parity T7: an explicit `inv_locale` cookie — set by the header's EN/NL
 * toggle (WebLocaleController) — wins over Accept-Language whenever present,
 * so a user who picks a language deliberately keeps it even if their browser
 * sends a different Accept-Language.
 */
class NegotiateLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $cookieLocale = $request->cookie(WebLocaleController::COOKIE);
        $locale = in_array($cookieLocale, ['en', 'nl'], true)
            ? $cookieLocale
            : ($request->getPreferredLanguage(['en', 'nl']) ?? 'en');

        App::setLocale($locale);

        /** @var Response $response */
        $response = $next($request);

        // Vary on Accept-Language AND Cookie: the effective locale (and the
        // display mode) are cookie-driven, so Accept-Language alone would let
        // a shared cache serve a cookie-picked NL body to an EN visitor.
        // Append rather than set — never clobber a Vary added earlier in the
        // stack (audit #19).
        $vary = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary', ''))));
        $response->headers->set('Vary', implode(', ', array_unique(array_merge($vary, ['Accept-Language', 'Cookie']))));

        return $response;
    }
}

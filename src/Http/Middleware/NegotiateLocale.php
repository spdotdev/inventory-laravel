<?php

namespace Spdotdev\Inventory\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Negotiates EN/NL from the browser's Accept-Language header for every page on
 * the inventory domain — landing, auth (login/register) and the session-guarded
 * /app pages alike, so a Dutch household sees the whole surface in Dutch, not
 * just the marketing page (GAP-6 M4). Mirrors the landing page's original
 * per-request negotiation, now centralized so every route gets it consistently.
 */
class NegotiateLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale($request->getPreferredLanguage(['en', 'nl']) ?? 'en');

        /** @var Response $response */
        $response = $next($request);

        // Vary: Accept-Language so proxies/CDNs cache the EN and NL bodies
        // separately instead of serving one negotiated variant to every locale.
        $response->headers->set('Vary', 'Accept-Language');

        return $response;
    }
}

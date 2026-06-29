<?php

namespace Spdotdev\Inventory\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = config('inventory.admin_token');

        // Admin token must be configured; a missing/empty token always fails.
        if (empty($token)) {
            abort(503, 'Admin API not configured.');
        }

        $provided = $request->bearerToken();

        if ($provided === null || ! hash_equals($token, $provided)) {
            abort(401, 'Invalid admin token.');
        }

        return $next($request);
    }
}

<?php

namespace Spdotdev\Inventory\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenancy gate for /households/{household}/* — verifies the authenticated user
 * is a member of the route's household BEFORE any resource access. Non-members
 * get 404 (not 403) so the endpoint never leaks whether a household exists.
 */
class EnsureHouseholdMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $household = $request->route('household');

        if (! $user instanceof User || ! $household instanceof Household) {
            abort(404);
        }

        abort_unless(
            $household->users()->whereKey($user->getKey())->exists(),
            404,
        );

        return $next($request);
    }
}

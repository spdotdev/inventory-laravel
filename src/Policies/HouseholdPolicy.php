<?php

namespace Spdotdev\Inventory\Policies;

use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;

/**
 * The package's only policy, and deliberately a seam rather than a feature.
 *
 * Every mutating storage-structure route authorizes against `restructure`. Today
 * it grants any member — matching the long-standing "all members are equal" rule.
 * When roles (owner/admin/member) land, THIS METHOD BODY is the thing that
 * changes; no call site moves.
 *
 * Note the 403-vs-404 posture: household.member already 404s non-members before
 * a policy ever runs, so a 403 from here can only ever mean "you are a member,
 * but not one who may restructure" — which is precisely the semantics roles need.
 */
class HouseholdPolicy
{
    public function restructure(User $user, Household $household): bool
    {
        return $household->users()->whereKey($user->getKey())->exists();
    }
}

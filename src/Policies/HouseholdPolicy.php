<?php

namespace Spdotdev\Inventory\Policies;

use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;

/**
 * `restructure` is the seam every mutating storage-structure route authorizes
 * against; that never changes when roles land — only this method's body did.
 * See `docs/superpowers/specs/2026-07-17-household-roles-design.md` for the
 * full role model (single transferable Owner; Admin manages structure and
 * membership; Member uses the household but can't reshape it).
 *
 * Note the 403-vs-404 posture throughout: `household.member` already 404s
 * non-members before any policy runs, so a 403 from here can only ever mean
 * "you are a member, but not one who may do this."
 */
class HouseholdPolicy
{
    /** Owner or Admin: rename/reorder/delete locations & shelves, edit theme. */
    public function restructure(User $user, Household $household): bool
    {
        return in_array($household->roleOf($user), ['owner', 'admin'], true);
    }

    /** Owner or Admin: promote/demote/remove members (never the Owner's own row). */
    public function manageMembers(User $user, Household $household): bool
    {
        return $this->restructure($user, $household);
    }

    /** Owner only: the sole path that changes who holds the Owner role. */
    public function transferOwnership(User $user, Household $household): bool
    {
        return $household->roleOf($user) === 'owner';
    }

    /** Owner only: deleting the household itself (not yet wired to a route). */
    public function delete(User $user, Household $household): bool
    {
        return $household->roleOf($user) === 'owner';
    }
}

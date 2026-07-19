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

    /** Owner only: deleting the household itself (wired to HouseholdController::destroy). */
    public function delete(User $user, Household $household): bool
    {
        return $household->roleOf($user) === 'owner';
    }

    /**
     * Owner/Admin can restore ANY batch (restructure), same as always. A
     * plain Member has no restructure grant but must still be able to undo
     * their OWN mistake — a Member who soft-deletes a product/shelf/location
     * had no way to bring it back before this method existed, which meant
     * the very first accidental delete was permanent for them in practice.
     * $batchOwnerId is Restorer::batchOwnerId's result: null means the batch
     * is unknown/already purged, which the caller must treat as "nothing to
     * restore" (see RestoreController), never reach this method with null to
     * mean "deny" — a Member probing random/expired batch ids should get the
     * same 409 an Owner would, not a 403 that leaks whether it ever existed.
     */
    public function restoreBatch(User $user, Household $household, ?int $batchOwnerId): bool
    {
        return $this->restructure($user, $household)
            || ($batchOwnerId !== null && $batchOwnerId === (int) $user->getKey());
    }
}

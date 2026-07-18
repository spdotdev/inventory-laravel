<?php

namespace Spdotdev\Inventory\Support;

use Illuminate\Support\Facades\DB;
use Spdotdev\Inventory\Models\Household;
use Spdotdev\Inventory\Models\User;

/**
 * The one writer for ownership transfer, shared by the API and web
 * controllers. The demote runs FIRST and is conditional on the caller's row
 * still being 'owner' at write time: two concurrent transfers (an owner
 * double-submitting to two different targets) would otherwise both pass the
 * policy gate — which reads outside any lock — and crown two owners, a state
 * nothing repairs (the join-time heal only fixes ZERO owners). The
 * conditional UPDATE takes the row lock, so the loser of the race matches
 * zero rows and the whole transaction rolls back before its promote runs.
 */
class OwnershipTransfer
{
    /**
     * @return bool false when the caller no longer held ownership (lost a
     *              concurrent transfer race) — nothing was changed.
     */
    public static function transfer(Household $household, User $newOwner, User $currentOwner): bool
    {
        return DB::transaction(function () use ($household, $newOwner, $currentOwner) {
            $demoted = DB::table('inventory_household_user')
                ->where('household_id', $household->getKey())
                ->where('user_id', $currentOwner->getKey())
                ->where('role', 'owner')
                ->update(['role' => 'admin']);

            if ($demoted === 0) {
                return false;
            }

            $household->users()->updateExistingPivot($newOwner->getKey(), ['role' => 'owner']);

            return true;
        });
    }
}

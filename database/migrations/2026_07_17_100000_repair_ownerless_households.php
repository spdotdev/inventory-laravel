<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair migration for the owner-less-household bug: between the roles release
 * (v0.1.12) and this fix, HouseholdController::store() attached creators
 * without a role, so households created in that window have no Owner at all —
 * their creator (role 'member') can't restructure or manage members, and
 * transfer-ownership is uncallable since it requires an existing Owner.
 *
 * Same rule as the original backfill: the earliest-joined member becomes
 * Owner. Idempotent — a household that already has an owner is untouched, so
 * re-running is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ownerlessHouseholdIds = DB::table('inventory_household_user')
            ->select('household_id')
            ->groupBy('household_id')
            ->havingRaw("SUM(CASE WHEN role = 'owner' THEN 1 ELSE 0 END) = 0")
            ->pluck('household_id');

        foreach ($ownerlessHouseholdIds as $householdId) {
            $ownerUserId = DB::table('inventory_household_user')
                ->where('household_id', $householdId)
                ->orderBy('joined_at')
                ->orderBy('user_id')
                ->value('user_id');

            if ($ownerUserId === null) {
                continue;
            }

            DB::table('inventory_household_user')
                ->where('household_id', $householdId)
                ->where('user_id', $ownerUserId)
                ->update(['role' => 'owner']);
        }
    }

    public function down(): void
    {
        // Irreversible by design — there is no record of which households were
        // repaired versus correct all along. Harmless to leave in place.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $householdIds = DB::table('inventory_household_user')->distinct()->pluck('household_id');

        foreach ($householdIds as $householdId) {
            $ownerUserId = DB::table('inventory_household_user')
                ->where('household_id', $householdId)
                ->orderBy('joined_at')
                ->value('user_id');

            if ($ownerUserId === null) {
                continue;
            }

            DB::table('inventory_household_user')
                ->where('household_id', $householdId)
                ->update(['role' => 'admin']);

            DB::table('inventory_household_user')
                ->where('household_id', $householdId)
                ->where('user_id', $ownerUserId)
                ->update(['role' => 'owner']);
        }
    }

    public function down(): void
    {
        // Irreversible by design — there is no recorded "who used to be equal"
        // state to restore. Rolling back the schema migration (which drops the
        // column) is the actual undo path.
    }
};

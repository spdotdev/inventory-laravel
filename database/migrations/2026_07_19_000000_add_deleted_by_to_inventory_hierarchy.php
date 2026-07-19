<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'inventory_storage_locations',
        'inventory_shelves',
        'inventory_products',
    ];

    public function up(): void
    {
        // A plain Member can delete a product/shelf/location (soft delete via
        // deletion_batch_id, see 2026_07_13_000000) but restoring one requires
        // Gate::authorize('restructure', ...), Owner/Admin only — so a Member
        // who deletes something by mistake has no way to undo their own
        // action. Stamping WHO deleted a row lets HouseholdPolicy@restoreBatch
        // grant restore of a batch to the member who created it, without
        // widening restore of anyone else's batch. FK-less, like the rest of
        // this hierarchy (references inventory_users.id).
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedBigInteger('deleted_by')->nullable()->after('deletion_batch_id');
                $table->index('deleted_by');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['deleted_by']);
                $table->dropColumn('deleted_by');
            });
        }
    }
};

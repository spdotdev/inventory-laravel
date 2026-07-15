<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'inventory_shelves',
        'inventory_products',
    ];

    public function up(): void
    {
        // C2 (final review): a delete strategy that MOVES a row instead of
        // killing it (move_products, unsort_products on a shelf; move_contents
        // on a location) never soft-deletes that row — it stays live, just
        // reparented. Undo had nothing recording where it came FROM, so restore
        // could only clear deleted_at/deletion_batch_id on rows the strategy
        // actually soft-deleted, and had no way to reverse a plain reparent —
        // "Undo" silently left the moved rows exactly where the strategy put
        // them. Stamping the original parent id here lets RestoreController
        // write shelf_id/location_id back to it.
        //
        // A moved row is never soft-deleted, so deletion_batch_id (already on
        // both tables) is ALSO stamped on it at move time — otherwise restore
        // would have no way to locate a live row as part of the batch at all.
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->unsignedBigInteger('restore_parent_id')->nullable()->after('deletion_batch_id');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('restore_parent_id');
            });
        }
    }
};

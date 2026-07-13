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
        // Reverses the package's original "hard deletes only" posture (2026-07-13
        // user decision). Deleting a location used to ON DELETE CASCADE its whole
        // subtree — every shelf and every product — with no confirmation and no
        // undo. deleted_at makes that survivable.
        //
        // deletion_batch_id groups every row killed by ONE user gesture: deleting
        // a shelf plus its twelve products is one batch, so Undo restores it as a
        // unit. The CLIENT mints the uuid, because deleting three shelves is three
        // requests and only the client knows they were one gesture.
        //
        // The ON DELETE CASCADE foreign keys stay: a soft delete is an UPDATE and
        // never triggers them, and they remain correct for the eventual hard purge.
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->softDeletes();
                $table->uuid('deletion_batch_id')->nullable()->after('deleted_at');
                $table->index('deletion_batch_id');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['deletion_batch_id']);
                $table->dropColumn(['deleted_at', 'deletion_batch_id']);
            });
        }
    }
};

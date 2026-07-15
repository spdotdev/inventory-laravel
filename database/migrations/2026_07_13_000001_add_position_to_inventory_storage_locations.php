<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_storage_locations', function (Blueprint $table) {
            // Locations were ordered by name; shelves already had `position`.
            // Drag-to-reorder needs the same column here. Existing rows all land
            // at 0, so index() falls back to the name tie-break and nothing
            // visibly moves until a user actually drags something.
            $table->unsignedInteger('position')->default(0)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_storage_locations', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};

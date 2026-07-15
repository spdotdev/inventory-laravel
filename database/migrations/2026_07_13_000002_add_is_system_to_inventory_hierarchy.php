<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_shelves', function (Blueprint $table) {
            // Marks the "Unsorted" shelf — the holding area for products whose
            // shelf was deleted but which the user chose to keep. It is created
            // lazily (only when something is first unsorted into it), cannot be
            // renamed, always sorts last, and cannot be deleted while occupied.
            $table->boolean('is_system')->default(false)->after('position');
        });

        Schema::table('inventory_storage_locations', function (Blueprint $table) {
            // Unused today. Added now so a future household-level holding area
            // (for products whose whole LOCATION was deleted) is a code change
            // rather than another migration against a live table.
            $table->boolean('is_system')->default(false)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_shelves', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });

        Schema::table('inventory_storage_locations', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};

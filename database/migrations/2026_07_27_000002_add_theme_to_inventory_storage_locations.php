<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_storage_locations', function (Blueprint $table) {
            // Mirrors the shelf theme columns (see
            // 2026_07_27_000001_add_theme_to_inventory_shelves.php, which
            // itself mirrors the household theme columns): user-chosen accent
            // + icon, stored as palette KEYS (see HouseholdColor/HouseholdIcon
            // enums, reused here) so clients render them in their own theme.
            // NULL = client derives a stable default from the location id.
            $table->string('color', 20)->nullable()->after('position');
            $table->string('icon', 20)->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_storage_locations', function (Blueprint $table) {
            $table->dropColumn(['color', 'icon']);
        });
    }
};

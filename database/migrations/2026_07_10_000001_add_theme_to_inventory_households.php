<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_households', function (Blueprint $table) {
            // Phase 2: user-chosen accent + icon, stored as palette KEYS (see
            // HouseholdColor/HouseholdIcon enums) so clients render them in
            // their own theme. NULL = client derives a stable default from the
            // household id (the pre-Phase-2 behaviour).
            $table->string('color', 20)->nullable()->after('join_code');
            $table->string('icon', 20)->nullable()->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_households', function (Blueprint $table) {
            $table->dropColumn(['color', 'icon']);
        });
    }
};

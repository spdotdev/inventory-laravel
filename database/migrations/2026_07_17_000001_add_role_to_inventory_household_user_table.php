<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_household_user', function (Blueprint $table) {
            // Default 'member' matches the invite-join behaviour: every code/link
            // join lands as Member, never Owner/Admin (see the roles design spec).
            $table->enum('role', ['owner', 'admin', 'member'])->default('member')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_household_user', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};

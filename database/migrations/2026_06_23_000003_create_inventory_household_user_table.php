<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_household_user', function (Blueprint $table) {
            $table->foreignId('household_id')->constrained('inventory_households')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('inventory_users')->cascadeOnDelete();
            $table->timestamp('joined_at')->useCurrent();
            // Composite PK; no role column — all members are equal (D-017).
            $table->primary(['household_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_household_user');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_storage_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('household_id')->constrained('inventory_households')->cascadeOnDelete();
            $table->string('name');
            $table->enum('type', ['freezer', 'fridge', 'pantry', 'other']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_storage_locations');
    }
};

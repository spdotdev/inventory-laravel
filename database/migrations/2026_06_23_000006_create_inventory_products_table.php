<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shelf_id')->constrained('inventory_shelves')->cascadeOnDelete();
            $table->string('name');
            // Quantity 0 = out of stock; row retained. Never below 0.
            $table->unsignedInteger('quantity')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_products');
    }
};

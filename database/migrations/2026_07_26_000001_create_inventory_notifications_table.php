<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('household_id')->nullable();
            $table->string('type');
            $table->json('payload')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();
            // The feed reader is always "this user's rows with id > cursor".
            $table->index(['user_id', 'id']);
            $table->index('created_at'); // prune scan
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_notifications');
    }
};

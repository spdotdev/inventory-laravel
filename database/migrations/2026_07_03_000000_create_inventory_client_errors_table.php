<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_client_errors', function (Blueprint $table) {
            $table->id();
            $table->string('device_id', 64);
            $table->string('error_code', 64);
            $table->text('message')->nullable();
            $table->string('app_version', 32)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_client_errors');
    }
};

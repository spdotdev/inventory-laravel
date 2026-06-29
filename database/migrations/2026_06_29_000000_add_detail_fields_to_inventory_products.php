<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
            $table->string('code')->nullable()->after('description');
            $table->boolean('is_mandatory')->default(false)->after('code');
            $table->string('image_url')->nullable()->after('is_mandatory');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            $table->dropColumn(['description', 'code', 'is_mandatory', 'image_url']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            // Phase 2 (unlocked 2026-07-10): per-product "running low" threshold.
            // NULL = the feature is off for that product; the hard-stop case
            // (is_mandatory + quantity 0) stays a separate concept.
            $table->unsignedInteger('low_stock_threshold')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            $table->dropColumn('low_stock_threshold');
        });
    }
};

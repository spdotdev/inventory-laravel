<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            // "Staples" — the things this household always keeps. Server-side
            // (unlike location/shelf favourites, which stay device-local) because
            // it is a household fact, not a personal one.
            //
            // A star is a MARKER and a FILTER, never a sort: manual drag order is
            // the user's stated order, and nothing may silently override it.
            $table->boolean('is_starred')->default(false)->after('is_mandatory');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            $table->dropColumn('is_starred');
        });
    }
};

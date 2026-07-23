<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // version_code is the source of truth for "is this newer" — Android's
        // BuildConfig.VERSION_CODE is an int, so ordering/comparison stays a
        // plain integer comparison on both ends. min_supported_version_code is
        // only meaningful when is_breaking is true (validated at the request
        // layer, not the schema layer, per this codebase's existing style of
        // nullable state-dependent fields — see DeleteShelfRequest).
        Schema::create('inventory_app_releases', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('version_code')->unique();
            $table->string('version_name');
            $table->boolean('is_breaking')->default(false);
            $table->unsignedInteger('min_supported_version_code')->nullable();
            $table->text('changelog');
            $table->string('download_url');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_app_releases');
    }
};

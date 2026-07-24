<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable, append-only audit trail — no updated_at, no retention/
        // prune. subject_label is a denormalized name snapshot so an entry
        // still reads sensibly after its target is later renamed/deleted.
        // subject_id is nullable for batch-summarized cascade-delete entries
        // (see HierarchyDeleter) that don't point at one single row.
        Schema::create('inventory_activity_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('household_id');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label');
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('household_id');
            $table->index(['household_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_activity_log');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which caretakers work for which owner, and their default access level.
 * Phase 4 adds the per-property assignments (a caretaker is assigned to
 * specific properties, not to all of the owner's properties).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_caretakers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('caretaker_id')->constrained('users')->cascadeOnDelete();
            $table->string('access_level', 20)->default('collector');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_id', 'caretaker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_caretakers');
    }
};

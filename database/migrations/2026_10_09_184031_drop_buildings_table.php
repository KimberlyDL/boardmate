<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buildings are removed (2026-10-09): a property is the building, so the
 * structure is Property → Room → Bedspace. Rolling back brings the empty
 * table and column back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropConstrainedForeignId('building_id');
        });

        Schema::dropIfExists('buildings');
    }

    public function down(): void
    {
        Schema::create('buildings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->unsignedSmallInteger('number');
            $table->timestamps();

            $table->unique(['owner_id', 'name']);
            $table->unique(['owner_id', 'number']);
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->after('owner_id')->constrained()->nullOnDelete();
        });
    }
};

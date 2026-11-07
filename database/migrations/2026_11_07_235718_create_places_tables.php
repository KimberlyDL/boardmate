<?php

use App\Services\Places\PlaceImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The official list of provinces and cities/municipalities (PSGC), so owners
 * pick a location instead of typing it (improvements Phase 4). Filled from
 * database/data/ph-places.json here and by `dormlink:import-places` later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provinces', function (Blueprint $table) {
            $table->id();
            $table->string('psgc_code', 10)->unique();
            $table->string('name', 80)->unique();
            $table->timestamps();
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('psgc_code', 10)->unique();
            $table->foreignId('province_id')->constrained()->cascadeOnDelete();
            // What people call it ("Calapan City"); the PSA name is kept too ("City of Calapan").
            $table->string('name', 80);
            $table->string('official_name', 80);
            $table->boolean('is_city')->default(false);
            $table->timestamps();

            $table->unique(['province_id', 'name']);
        });

        app(PlaceImporter::class)->import();
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
        Schema::dropIfExists('provinces');
    }
};

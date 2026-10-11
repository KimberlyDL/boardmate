<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Campuses the Dorm Finder measures distance from; the admin keeps the list and picks the default. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campuses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        DB::statement('create unique index campuses_one_default on campuses (is_default) where is_default');
    }

    public function down(): void
    {
        Schema::dropIfExists('campuses');
    }
};

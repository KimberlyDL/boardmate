<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rooms (System Design B1): every property has at least one room, and each
 * room is rented whole or by bedspace. The rental mode moves from the
 * property to its rooms; existing properties get one room carrying their old
 * mode, and their units move into it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->smallInteger('floor')->nullable();
            $table->string('rental_mode', 20);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['property_id', 'number']);
            $table->index(['property_id', 'deleted_at', 'floor', 'number']);
        });

        Schema::table('rentable_units', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('property_id')->constrained()->cascadeOnDelete();
        });

        DB::statement('
            insert into rooms (property_id, number, floor, rental_mode, sort_order, created_at, updated_at, deleted_at)
            select id, 1, null, rental_mode, 1, created_at, updated_at, deleted_at from properties
        ');
        DB::statement('
            update rentable_units set room_id = rooms.id
            from rooms where rooms.property_id = rentable_units.property_id
        ');

        Schema::table('rentable_units', function (Blueprint $table) {
            $table->unsignedBigInteger('room_id')->nullable(false)->change();
            $table->index(['room_id', 'deleted_at', 'sort_order']);
        });
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('rental_mode');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('rental_mode', 20)->default('bedspaces');
        });
        DB::statement('
            update properties set rental_mode = rooms.rental_mode
            from (select distinct on (property_id) property_id, rental_mode from rooms order by property_id, number) as rooms
            where rooms.property_id = properties.id
        ');
        Schema::table('rentable_units', function (Blueprint $table) {
            $table->dropIndex(['room_id', 'deleted_at', 'sort_order']);
            $table->dropConstrainedForeignId('room_id');
        });
        Schema::dropIfExists('rooms');
    }
};

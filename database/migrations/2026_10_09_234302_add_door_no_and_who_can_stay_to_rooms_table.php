<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The door number the owner paints on the room (optional, unique within the
 * property, ignoring case and archived rooms) and who the room is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('door_no', 10)->nullable()->after('floor');
            $table->string('who_can_stay', 20)->default('any')->after('rental_mode');
        });

        DB::statement('create unique index rooms_property_door_no_unique on rooms (property_id, lower(door_no)) where door_no is not null and deleted_at is null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists rooms_property_door_no_unique');

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['door_no', 'who_can_stay']);
        });
    }
};

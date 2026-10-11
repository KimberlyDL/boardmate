<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bedspace room can also be rented whole (step 6): an extra whole unit
 * marked `is_whole_option`. It and the room's bedspaces block each other:
 * `blocked` is set on one side while the other side is in use. Applications
 * say whether the boarder wants the whole room.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentable_units', function (Blueprint $table) {
            $table->boolean('is_whole_option')->default(false)->after('kind');
            $table->boolean('blocked')->default(false)->after('not_ready');
        });

        Schema::table('booking_applications', function (Blueprint $table) {
            $table->boolean('wants_whole')->default(false)->after('room_id');
        });
    }

    public function down(): void
    {
        Schema::table('booking_applications', function (Blueprint $table) {
            $table->dropColumn('wants_whole');
        });

        Schema::table('rentable_units', function (Blueprint $table) {
            $table->dropColumn(['is_whole_option', 'blocked']);
        });
    }
};

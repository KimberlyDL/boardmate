<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval notes who will stay and whether the applicant becomes the room's
 * leader (B3); move-in turns the reservation into a tenancy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_applications', function (Blueprint $table) {
            $table->json('planned_occupants')->nullable()->after('reserved_until');
            $table->boolean('leader_on_move_in')->default(false)->after('planned_occupants');
            $table->timestamp('moved_in_at')->nullable()->after('leader_on_move_in');
        });
    }

    public function down(): void
    {
        Schema::table('booking_applications', function (Blueprint $table) {
            $table->dropColumn(['planned_occupants', 'leader_on_move_in', 'moved_in_at']);
        });
    }
};

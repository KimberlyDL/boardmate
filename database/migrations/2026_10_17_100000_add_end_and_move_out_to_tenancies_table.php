<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C1: a tenancy's approved end date (the last day of stay; the unit is free
 * the day after) and the move-out staff recorded. Notices themselves live in
 * tenancy_notices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenancies', function (Blueprint $table) {
            $table->date('end_on')->nullable()->after('anchor_day');
            $table->date('moved_out_on')->nullable()->after('end_on');
            $table->foreignId('moved_out_by')->nullable()->after('moved_out_on')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenancies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('moved_out_by');
            $table->dropColumn(['end_on', 'moved_out_on']);
        });
    }
};

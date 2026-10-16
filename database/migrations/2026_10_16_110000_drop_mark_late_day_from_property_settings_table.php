<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C7 (owner decision, 2026-10-16): a bill is marked late the day after the
 * grace days, so "mark late on day" is no longer a separate setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->dropColumn('mark_late_day');
        });
    }

    public function down(): void
    {
        Schema::table('property_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('mark_late_day')->default(4)->after('mark_late_enabled');
        });
    }
};

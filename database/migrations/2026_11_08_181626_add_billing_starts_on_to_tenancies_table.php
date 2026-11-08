<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Existing tenants: someone already living at the place when it started
 * using DormLink keeps their real move-in date, and DormLink bills only the
 * periods starting on or after this day (the ones before were settled
 * outside DormLink). Null for a normal move-in: billing starts at period 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenancies', function (Blueprint $table) {
            $table->date('billing_starts_on')->nullable()->after('anchor_day');
        });
    }

    public function down(): void
    {
        Schema::table('tenancies', function (Blueprint $table) {
            $table->dropColumn('billing_starts_on');
        });
    }
};

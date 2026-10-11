<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a metered bill was split, kept with it: per room the occupant-days, the
 * amount and whether the owner waived it. A correction re-splits the new total
 * the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utility_bills', function (Blueprint $table) {
            $table->json('shares')->nullable()->after('total_centavos');
            $table->string('correction_reason', 255)->nullable()->after('replaces_id');
        });
    }

    public function down(): void
    {
        Schema::table('utility_bills', function (Blueprint $table) {
            $table->dropColumn(['shares', 'correction_reason']);
        });
    }
};

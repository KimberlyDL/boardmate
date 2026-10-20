<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D3: the house-rules version the boarder accepted when applying, so the
 * move-in sheet can tell whether an in-person confirmation is still needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_applications', function (Blueprint $table) {
            $table->unsignedInteger('house_rules_version')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('booking_applications', function (Blueprint $table) {
            $table->dropColumn('house_rules_version');
        });
    }
};

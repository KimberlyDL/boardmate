<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The room the boarder applied for (their preference; the owner may place them elsewhere). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_applications', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('property_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('booking_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
        });
    }
};

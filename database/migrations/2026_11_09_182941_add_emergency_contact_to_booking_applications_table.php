<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The applicant gives their own emergency contact when applying (filled in
 * from their profile), so the owner does not type it at move-in. Older
 * applications have none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_applications', function (Blueprint $table) {
            $table->string('emergency_contact_name', 120)->nullable()->after('contact_phone');
            $table->string('emergency_contact_relationship', 50)->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_phone', 30)->nullable()->after('emergency_contact_relationship');
        });
    }

    public function down(): void
    {
        Schema::table('booking_applications', function (Blueprint $table) {
            $table->dropColumn(['emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email')->nullable()->after('email');
            $table->timestamp('pending_email_requested_at')->nullable()->after('pending_email');
            $table->string('google_id')->nullable()->unique()->after('pending_email_requested_at');
            // Accounts created with Google have no password until they set one.
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['pending_email', 'pending_email_requested_at', 'google_id']);
            $table->string('password')->nullable(false)->change();
        });
    }
};

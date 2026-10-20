<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D2: late arrivals without an approved request, logged by staff. A log
 * only: repeated ones are flagged for the owner, never charged by the app.
 * Also the tenant's mute switch for the curfew reminder, and which nights
 * a property's reminder was already sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curfew_violations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenancy_id')->constrained()->cascadeOnDelete();
            $table->date('on_date');
            $table->string('arrived_at', 5)->nullable(); // HH:MM
            $table->string('note', 500)->nullable();
            $table->foreignId('logged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'on_date']);
            $table->index(['tenancy_id', 'on_date']);
        });

        Schema::table('tenancies', function (Blueprint $table) {
            $table->boolean('curfew_reminders_muted')->default(false);
        });

        Schema::create('curfew_reminder_runs', function (Blueprint $table) {
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->date('curfew_on');
            $table->timestamp('sent_at');

            $table->primary(['property_id', 'curfew_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curfew_reminder_runs');
        Schema::table('tenancies', function (Blueprint $table) {
            $table->dropColumn('curfew_reminders_muted');
        });
        Schema::dropIfExists('curfew_violations');
    }
};

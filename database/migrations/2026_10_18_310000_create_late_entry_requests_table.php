<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D2: a tenant asks in advance to come back after curfew on a date. Staff
 * approve or decline (with a reason); an approved late arrival is never a
 * violation. Pending → Approved | Declined, or Cancelled by the tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('late_entry_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenancy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->date('on_date');
            $table->string('expected_arrival', 5); // HH:MM
            $table->string('reason', 500);
            $table->string('status', 20);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decline_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['property_id', 'status', 'on_date']);
            $table->index(['tenancy_id', 'on_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('late_entry_requests');
    }
};

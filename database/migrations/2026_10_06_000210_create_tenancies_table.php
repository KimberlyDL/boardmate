<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenancies (System Design C1): a stay from move-in. In a bedspace room each
 * bedspacer has one; in a room rented whole the leader has it. At most one
 * current (not ended or settled) tenancy per unit and per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenancies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('rentable_units')->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('booking_application_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('active');

            $table->date('moved_in_on');
            // Day of the month rent is due, from the move-in day (29-31 fall on a month's last day).
            $table->unsignedTinyInteger('anchor_day');

            $table->string('emergency_contact_name', 120);
            $table->string('emergency_contact_relationship', 50)->nullable();
            $table->string('emergency_contact_phone', 30);

            // Set once house rules exist (D3).
            $table->timestamp('house_rules_accepted_at')->nullable();
            // Moved in without the deposit and first rent recorded (SC-33).
            $table->text('activation_override_reason')->nullable();
            $table->foreignId('activation_overridden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('moved_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'status']);
            $table->index(['room_id', 'status']);
            $table->index(['tenant_id', 'status']);
        });

        DB::statement("create unique index tenancies_one_current_per_unit on tenancies (unit_id) where status not in ('ended', 'settled')");
        DB::statement("create unique index tenancies_one_current_per_tenant on tenancies (tenant_id) where status not in ('ended', 'settled')");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenancies');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking applications and reservations (F2). A boarder applies to a
 * property; the owner or a Manager picks the unit when approving.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('rentable_units')->nullOnDelete();
            $table->foreignId('boarder_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');

            $table->date('planned_move_in_on');
            $table->text('message')->nullable();
            $table->string('contact_phone', 30);
            $table->string('id_document_path')->nullable();
            $table->timestamp('id_document_purged_at')->nullable();

            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            // Last day the reservation holds (inclusive): planned move-in + expiry days.
            $table->date('reserved_until')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('closed_reason')->nullable();
            $table->timestamps();

            $table->index(['boarder_id', 'status']);
            $table->index(['property_id', 'status']);
            $table->index(['status', 'reserved_until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_applications');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1: a problem reported by a boarder (for their room) or logged by staff
 * (a room or the whole place). Submitted → In progress → Resolved → Closed,
 * or Cancelled with a reason. The repair cost is for the owner's records;
 * charging the boarder is a separate, manual one-time charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenancy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->string('category', 20);
            $table->string('description', 2000);
            $table->string('urgency', 10);
            $table->string('status', 20);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('repair_cost_centavos')->nullable();
            $table->string('resolution', 1000)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'status']);
            $table->index(['reported_by', 'status']);
        });

        Schema::create('maintenance_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('maintenance_requests')->cascadeOnDelete();
            $table->string('path');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('maintenance_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('maintenance_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('body', 1000);
            $table->timestamp('created_at')->nullable();

            $table->index(['request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_notes');
        Schema::dropIfExists('maintenance_photos');
        Schema::dropIfExists('maintenance_requests');
    }
};

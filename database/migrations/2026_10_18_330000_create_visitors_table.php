<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D4: a visitor of someone staying here, registered ahead by the tenant or
 * logged as a walk-in by staff. Overnight stays need approval when the
 * property says so, and count toward a nights-per-month limit (going over is
 * flagged, not blocked). Only the name is kept (contact optional), and
 * records are deleted 90 days after the visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenancy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('contact', 50)->nullable();
            $table->date('visit_on');
            $table->string('expected_in', 5)->nullable(); // HH:MM
            $table->string('expected_out', 5)->nullable();
            $table->boolean('overnight')->default(false);
            $table->unsignedTinyInteger('nights')->default(0);
            $table->string('status', 20);
            $table->boolean('is_walk_in')->default(false);
            $table->boolean('over_limit')->default(false);
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decline_reason', 255)->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamps();

            $table->index(['property_id', 'visit_on']);
            $table->index(['tenancy_id', 'visit_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors');
    }
};

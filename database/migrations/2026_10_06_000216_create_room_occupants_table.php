<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Occupants of a room rented whole (C2): everyone who stays, with join and
 * leave dates for the occupant-days split. In a bedspace room the members are
 * the tenancies, so this table is not used there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_occupants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenancy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('contact_phone', 30)->nullable();
            $table->date('joined_on');
            $table->date('left_on')->nullable();

            $table->string('emergency_contact_name', 120);
            $table->string('emergency_contact_relationship', 50)->nullable();
            $table->string('emergency_contact_phone', 30);

            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['room_id', 'left_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_occupants');
    }
};

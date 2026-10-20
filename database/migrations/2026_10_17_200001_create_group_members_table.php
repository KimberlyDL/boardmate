<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D5: who is in a room's group, kept in step with the room. In a bedspace
 * room each member is a bedspacer (tenancy_id); in a whole room each is a
 * listed occupant (room_occupant_id; an account is optional). Former members
 * stay with their leave date, so past shares keep their names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('contribution_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('tenancy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_occupant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('role', 20)->default('member');
            $table->date('joined_on');
            $table->date('left_on')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'tenancy_id']);
            $table->unique(['group_id', 'room_occupant_id']);
            $table->index(['group_id', 'left_on']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_members');
    }
};

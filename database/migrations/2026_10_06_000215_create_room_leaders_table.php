<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Room leaders (C2): one current leader per room, with the consent recorded
 * (date and who approved). Past leaders are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_leaders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('appointed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('consent_recorded_at');
            $table->foreignId('consent_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->text('ended_reason')->nullable();
            $table->timestamps();

            $table->index('user_id');
        });

        DB::statement('create unique index room_leaders_one_current_per_room on room_leaders (room_id) where ended_on is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('room_leaders');
    }
};

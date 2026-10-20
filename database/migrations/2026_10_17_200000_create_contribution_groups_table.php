<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D5: one shared-contributions group per room, created automatically. Its
 * default split method starts from the property's "How bills are split".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contribution_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('default_split_method', 20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contribution_groups');
    }
};

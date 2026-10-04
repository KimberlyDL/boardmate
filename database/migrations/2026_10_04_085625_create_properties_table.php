<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('type', 30);
            $table->text('description')->nullable();
            // Shown on the listing, never enforced (F1, F2).
            $table->string('who_can_apply', 120)->nullable();

            $table->string('street')->nullable();
            $table->string('barangay', 120)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('province', 120)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('rental_mode', 20);
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['owner_id', 'deleted_at']);
            $table->index(['is_published', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C1 (SC-10, SC-11, SC-29): every end date asked for or set, kept as history.
 * A boarder's notice is Pending until the owner or a caretaker approves or
 * declines it; an end date staff set themselves (no notice, or an extension)
 * is Approved at once with a reason. Short notice is only flagged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenancy_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenancy_id')->constrained()->cascadeOnDelete();
            $table->date('end_on');
            $table->string('status', 20);
            $table->boolean('is_short_notice')->default(false);
            $table->string('reason', 255)->nullable();
            $table->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decline_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['tenancy_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenancy_notices');
    }
};

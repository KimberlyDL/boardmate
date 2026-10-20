<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D3: who accepted which version of a property's house rules, when and how.
 * A boarder accepts when applying (`booking_application_id`); at move-in the
 * acceptance carries over to the tenancy, or staff record that it was given
 * in person; a tenant acknowledges each later version in the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('house_rule_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('house_rule_versions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenancy_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('booking_application_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('method', 20);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at');

            $table->index(['tenancy_id', 'version_id']);
            $table->index(['booking_application_id', 'version_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('house_rule_acceptances');
    }
};

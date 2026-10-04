<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which caretaker runs which property, with the access level for THAT
 * property (Features guide: "Collector or Manager, per property").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_caretakers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('caretaker_id')->constrained('users')->cascadeOnDelete();
            $table->string('access_level', 20);
            $table->timestamps();

            $table->unique(['property_id', 'caretaker_id']);
            $table->index('caretaker_id');
        });

        Schema::table('caretaker_invitations', function (Blueprint $table) {
            // Properties to assign when the invitation is accepted.
            $table->json('property_ids')->nullable()->after('access_level');
        });
    }

    public function down(): void
    {
        Schema::table('caretaker_invitations', fn (Blueprint $table) => $table->dropColumn('property_ids'));
        Schema::dropIfExists('property_caretakers');
    }
};

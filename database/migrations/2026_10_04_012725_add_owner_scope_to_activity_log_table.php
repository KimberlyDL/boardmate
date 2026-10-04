<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit module columns: which owner's business an entry belongs to (so each
 * owner sees only their own log) and the role the actor acted in.
 * owner_id is not a foreign key on purpose: the audit trail must survive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->unsignedBigInteger('owner_id')->nullable()->index()->after('causer_id');
            $table->string('acting_as', 30)->nullable()->after('owner_id');
            $table->index(['log_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['log_name', 'created_at']);
            $table->dropColumn(['owner_id', 'acting_as']);
        });
    }
};

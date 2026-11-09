<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A former member hides an old room group from their own list once they
 * left and are settled up. Only their list changes: the group's records
 * stay for the others and the owner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_members', function (Blueprint $table) {
            $table->timestamp('hidden_at')->nullable()->after('left_on');
        });
    }

    public function down(): void
    {
        Schema::table('group_members', function (Blueprint $table) {
            $table->dropColumn('hidden_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->string('photo_path')->nullable()->after('phone');
            $table->string('active_role', 30)->nullable()->after('photo_path');
            $table->timestamp('consented_at')->nullable()->after('active_role');
            $table->string('privacy_policy_version', 30)->nullable()->after('consented_at');
            $table->jsonb('muted_notifications')->nullable()->after('privacy_policy_version');
            $table->timestamp('suspended_at')->nullable()->after('muted_notifications');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'phone', 'photo_path', 'active_role', 'consented_at',
                'privacy_policy_version', 'muted_notifications', 'suspended_at',
            ]);
        });
    }
};

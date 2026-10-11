<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A utility account covers the whole property (null) or one room's own meter. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utility_accounts', function (Blueprint $table) {
            $table->foreignId('room_id')->nullable()->after('property_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('utility_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
        });
    }
};

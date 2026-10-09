<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A discount a bill used is locked, like a locked price: it can still be
 * ended by a later change, but never deleted or replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenancy_discounts', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable()->after('set_by');
        });
    }

    public function down(): void
    {
        Schema::table('tenancy_discounts', function (Blueprint $table) {
            $table->dropColumn('locked_at');
        });
    }
};

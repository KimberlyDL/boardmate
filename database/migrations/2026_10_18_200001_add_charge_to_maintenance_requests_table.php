<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D1: the one-time charge the owner or a Manager added for a repair the
 * boarder caused (never automatic), and the unit set Not ready for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->foreignId('charge_bill_id')->nullable()->constrained('bills')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('rentable_units')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('charge_bill_id');
            $table->dropConstrainedForeignId('unit_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C8: what was deducted at settlement (each is also a one-time charge bill),
 * copied here for the summary, and any unused credit (an earlier
 * overpayment) paid back with the refund.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->json('deductions')->nullable()->after('deductions_centavos');
            $table->unsignedBigInteger('credit_returned_centavos')->default(0)->after('refund_centavos');
        });
    }

    public function down(): void
    {
        Schema::table('settlements', function (Blueprint $table) {
            $table->dropColumn(['deductions', 'credit_returned_centavos']);
        });
    }
};

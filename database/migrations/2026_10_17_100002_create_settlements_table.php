<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C8: the deposit settled once per tenancy after move-out. The deposit held
 * is applied to what is owed (a payment with method "deposit", kept in
 * payment_id), deductions are one-time charges, and the rest is refunded or
 * kept with a reason. Amounts are copied here so the summary never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenancy_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('deposit_held_centavos');
            $table->unsignedBigInteger('deductions_centavos')->default(0);
            $table->unsignedBigInteger('applied_centavos')->default(0);
            $table->unsignedBigInteger('refund_centavos')->default(0);
            $table->unsignedBigInteger('kept_centavos')->default(0);
            $table->string('kept_reason', 255)->nullable();
            $table->unsignedBigInteger('still_owed_centavos')->default(0);
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('refund_method', 20)->nullable();
            $table->string('refund_reference', 100)->nullable();
            $table->date('refunded_on')->nullable();
            $table->string('note', 500)->nullable();
            $table->foreignId('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settlements');
    }
};

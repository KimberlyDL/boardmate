<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How money settles charges: a confirmed payment, or a negative adjustment
 * line (credit), applied to a charge line. Oldest charge first; on the same
 * due date rent, then utilities, then other charges (C6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('credit_line_id')->nullable()->constrained('bill_lines')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_centavos');
            $table->timestamps();

            $table->index('payment_id');
            $table->index('credit_line_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};

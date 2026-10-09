<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money received at move-in: the deposit and the advance rent for the first
 * period (no utilities). Separate from bills; the payments phase extends it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenancy_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenancy_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->unsignedBigInteger('amount_centavos');
            $table->string('method', 20);
            $table->date('received_on');
            $table->string('reference', 100)->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenancy_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenancy_payments');
    }
};

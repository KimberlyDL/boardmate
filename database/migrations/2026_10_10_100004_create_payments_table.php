<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money a payer gave the owner, recorded by hand (C6; no payment gateway yet).
 * Staff-recorded payments are confirmed at once; a boarder's report waits as
 * `pending` until the owner or a caretaker confirms or rejects it. A mistake is
 * reversed by a second row (`reverses_id`), never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('amount_centavos');
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->string('proof_path')->nullable();
            $table->date('paid_on');
            $table->string('note', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->string('receipt_number', 40)->nullable();
            $table->foreignId('reverses_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'status']);
            $table->index(['payer_id', 'status']);
            $table->index('reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};

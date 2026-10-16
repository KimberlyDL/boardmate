<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C7: every payment reminder sent, per bill. The daily job can run twice, so a
 * bill gets one reminder of each kind per day at most (database-enforced).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payer_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->date('sent_on');
            $table->bigInteger('balance_centavos');
            $table->foreignId('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['bill_id', 'kind', 'sent_on']);
            $table->index(['payer_id', 'sent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_reminders');
    }
};

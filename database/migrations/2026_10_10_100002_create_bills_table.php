<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bill to one payer (C4). `periodic` = the anniversary bill (rent for the
 * period starting + fixed utilities for the period just finished);
 * `utility` = a room's share of a metered utility bill, to its leader;
 * `charge` = a one-time charge. Issued bills are never edited or deleted:
 * corrections are adjustment lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tenancy_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('room_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('utility_bill_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->string('number', 40);
            $table->date('period_start_on')->nullable();
            $table->date('period_end_on')->nullable();
            $table->date('issued_on');
            $table->date('due_on');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The daily job can run twice: one periodic bill per tenancy period, one share per room.
            $table->unique(['tenancy_id', 'kind', 'period_start_on']);
            $table->unique(['utility_bill_id', 'room_id']);
            $table->index(['property_id', 'due_on']);
            $table->index(['payer_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};

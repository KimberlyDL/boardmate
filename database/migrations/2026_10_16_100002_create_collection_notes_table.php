<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C7 (SC-32): what the owner or a collector noted about a payer who is behind,
 * e.g. after a visit, with an optional date they promised to pay. Notes only:
 * nothing is automated from them. Never edited or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collection_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payer_id')->constrained('users')->cascadeOnDelete();
            $table->string('note', 500);
            $table->date('promised_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'payer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_notes');
    }
};

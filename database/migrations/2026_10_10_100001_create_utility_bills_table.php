<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A provider's bill for an actual-bill utility account, entered by the owner
 * or a Manager when it arrives (C3, SC-03). A correction is a new row that
 * `replaces_id` the wrong one; the difference goes out as adjustment lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utility_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('utility_account_id')->constrained()->cascadeOnDelete();
            $table->date('coverage_from_on');
            $table->date('coverage_to_on');
            $table->unsignedBigInteger('total_centavos');
            $table->string('photo_path')->nullable();
            $table->foreignId('replaces_id')->nullable()->constrained('utility_bills')->nullOnDelete();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['utility_account_id', 'coverage_from_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_bills');
    }
};

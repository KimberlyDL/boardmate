<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a bill charges. Amounts are signed: an adjustment that lowers a charge
 * is negative and works as a credit. Lines are only ever added, never edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('description', 160);
            $table->bigInteger('amount_centavos');
            $table->foreignId('unit_id')->nullable()->constrained('rentable_units')->nullOnDelete();
            $table->foreignId('price_rule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discount_id')->nullable()->constrained('tenancy_discounts')->nullOnDelete();
            $table->foreignId('utility_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('adjusts_line_id')->nullable()->constrained('bill_lines')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_lines');
    }
};

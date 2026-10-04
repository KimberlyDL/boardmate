<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Effective-dated prices (Billing guide S4): a price is never overwritten; the
 * old rule closes and a new one starts. Prices a unit's rent or a utility
 * account's fixed amount. Per-tenancy agreed rates come with tenancies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->morphs('priceable');
            $table->unsignedBigInteger('amount_centavos');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['priceable_type', 'priceable_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_rules');
    }
};

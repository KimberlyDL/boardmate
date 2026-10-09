<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tenancy's manual rent discount (C3), effective-dated and never
 * overwritten: changing it ends the old row and adds a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenancy_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenancy_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            // Centavos for a fixed discount, basis points for a percent one.
            $table->unsignedBigInteger('value');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenancy_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenancy_discounts');
    }
};

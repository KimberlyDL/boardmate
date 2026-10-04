<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The whole property or one bedspace (D1). Rent lives in price_rules. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rentable_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('label', 80);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->string('status', 20)->default('available');
            $table->boolean('not_ready')->default(false);
            $table->string('not_ready_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['property_id', 'deleted_at', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rentable_units');
    }
};

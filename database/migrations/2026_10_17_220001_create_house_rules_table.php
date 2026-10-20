<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** D3: the rules of one version, in the owner's order, each in a category. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('house_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('house_rule_versions')->cascadeOnDelete();
            $table->string('category', 20);
            $table->string('body', 500);
            $table->unsignedSmallInteger('position');

            $table->index(['version_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('house_rules');
    }
};

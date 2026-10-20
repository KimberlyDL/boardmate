<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D2/D4 in D3: lines written by the app from the curfew and visitor
 * settings, kept in each version like the owner's own rules so the text a
 * boarder accepted never changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('house_rules', function (Blueprint $table) {
            $table->boolean('is_auto')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('house_rules', function (Blueprint $table) {
            $table->dropColumn('is_auto');
        });
    }
};

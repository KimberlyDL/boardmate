<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D3: a property's house rules, kept as numbered versions. A row with no
 * version number is the draft being edited (at most one per property);
 * publishing gives it the next number. Old versions are never changed, so
 * the version in force on any date can be shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('house_rule_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['property_id', 'version']);
        });

        DB::statement('CREATE UNIQUE INDEX house_rule_versions_one_draft ON house_rule_versions (property_id) WHERE version IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('house_rule_versions');
    }
};

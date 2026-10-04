<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proof files sent with an owner application (private). The file is
     * deleted 90 days after the review decision; the row stays as a record
     * of what was sent (path null, purged_at set).
     */
    public function up(): void
    {
        Schema::create('owner_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_profile_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('path')->nullable();
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->index(['owner_profile_id', 'purged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_documents');
    }
};

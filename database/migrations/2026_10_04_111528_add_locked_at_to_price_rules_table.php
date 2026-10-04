<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A price rule a bill was built from is locked: PriceBook never deletes or
     * edits it again (it can still be closed by a later price).
     */
    public function up(): void
    {
        Schema::table('price_rules', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable()->after('set_by');
        });
    }

    public function down(): void
    {
        Schema::table('price_rules', function (Blueprint $table) {
            $table->dropColumn('locked_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `path` is now the large WebP (1600 px) and `thumb_path` the 480 px one
     * for cards and map popups. Null until `boardmate:rebuild-images` runs
     * for photos uploaded before this.
     */
    public function up(): void
    {
        Schema::table('property_photos', function (Blueprint $table) {
            $table->string('thumb_path')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('property_photos', function (Blueprint $table) {
            $table->dropColumn('thumb_path');
        });
    }
};

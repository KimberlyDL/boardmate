<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The building number (B1, B2) used in room codes; assigned in order per owner. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->unsignedSmallInteger('number')->nullable()->after('name');
        });

        DB::statement('
            update buildings set number = numbered.n
            from (select id, row_number() over (partition by owner_id order by id) as n from buildings) as numbered
            where buildings.id = numbered.id
        ');

        Schema::table('buildings', function (Blueprint $table) {
            $table->unsignedSmallInteger('number')->nullable(false)->change();
            $table->unique(['owner_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('buildings', function (Blueprint $table) {
            $table->dropUnique(['owner_id', 'number']);
            $table->dropColumn('number');
        });
    }
};

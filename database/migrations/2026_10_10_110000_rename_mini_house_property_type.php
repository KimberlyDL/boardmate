<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** "Mini house" is no longer a property type: such places become "House". */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('properties')->where('type', 'mini_house')->update(['type' => 'house']);
    }

    public function down(): void
    {
        // Nothing to undo: "house" is a valid type either way.
    }
};

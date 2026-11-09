<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Collect first" lines in a room group: a bill due later (e.g. the
 * internet), split now so members pay their share to the collector before
 * it is due. `due_on` is when the bill must be paid; `paid_on` is when the
 * collector paid it (null while still collecting).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_expenses', function (Blueprint $table) {
            $table->date('due_on')->nullable()->after('spent_on');
            $table->date('paid_on')->nullable()->after('due_on');
        });
    }

    public function down(): void
    {
        Schema::table('group_expenses', function (Blueprint $table) {
            $table->dropColumn(['due_on', 'paid_on']);
        });
    }
};

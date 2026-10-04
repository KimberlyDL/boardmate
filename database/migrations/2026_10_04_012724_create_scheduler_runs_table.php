<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per daily job per day, so `boardmate:daily` is safe to run twice:
 * a job that already succeeded for a day is skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduler_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job', 100);
            $table->date('run_on');
            $table->string('status', 20); // running, succeeded, failed
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->unique(['job', 'run_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduler_runs');
    }
};

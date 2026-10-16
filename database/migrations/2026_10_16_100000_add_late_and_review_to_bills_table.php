<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C7: a bill still unpaid after the grace days is marked late, with a
 * numbered first notice (NTC-…). The owner or a Manager can put a bill under
 * review, which stops its reminders and late marking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->timestamp('late_at')->nullable()->after('issued_by');
            $table->string('late_notice_number', 40)->nullable()->after('late_at');
            $table->foreignId('late_marked_by')->nullable()->after('late_notice_number')->constrained('users')->nullOnDelete();
            $table->timestamp('under_review_at')->nullable()->after('late_marked_by');
            $table->foreignId('under_review_by')->nullable()->after('under_review_at')->constrained('users')->nullOnDelete();
            $table->string('under_review_reason', 255)->nullable()->after('under_review_by');
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('late_marked_by');
            $table->dropConstrainedForeignId('under_review_by');
            $table->dropColumn(['late_at', 'late_notice_number', 'under_review_at', 'under_review_reason']);
        });
    }
};

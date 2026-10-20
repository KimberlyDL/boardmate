<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D5: what the group shares. An owner bill posted to the group is a locked
 * line (bill_id; its amount never changes here, only the split). A member's
 * own expense has the member who paid it. A correction after someone has
 * repaid is an adjustment (adjusts_expense_id; signed amount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('contribution_groups')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('adjusts_expense_id')->nullable()->constrained('group_expenses')->nullOnDelete();
            $table->foreignId('paid_by_member_id')->constrained('group_members')->restrictOnDelete();
            $table->string('description', 160);
            $table->bigInteger('amount_centavos');
            $table->date('spent_on');
            $table->string('split_method', 20);
            $table->json('split_params')->nullable();
            $table->string('proof_path')->nullable();
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group_id', 'bill_id']);
            $table->index(['group_id', 'spent_on']);
        });

        Schema::create('expense_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('group_expenses')->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('group_members')->restrictOnDelete();
            $table->bigInteger('amount_centavos');
            $table->timestamps();

            $table->unique(['expense_id', 'member_id']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_shares');
        Schema::dropIfExists('group_expenses');
    }
};

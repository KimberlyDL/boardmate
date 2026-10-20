<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D5: a member paying back another (the leader for owner bills, or whoever
 * paid an expense). Two steps: marked paid, then confirmed or rejected by the
 * one paid (or the leader / a moderator). Settled one by one, no netting.
 * The owner never sees these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_repayments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('contribution_groups')->cascadeOnDelete();
            $table->foreignId('from_member_id')->constrained('group_members')->restrictOnDelete();
            $table->foreignId('to_member_id')->constrained('group_members')->restrictOnDelete();
            $table->unsignedBigInteger('amount_centavos');
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->string('proof_path')->nullable();
            $table->string('note', 255)->nullable();
            $table->string('status', 20)->default('pending');
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['group_id', 'status']);
            $table->index(['from_member_id', 'to_member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_repayments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every owner setting per property (Billing guide "Owner settings reference",
 * S8 default escalation timings, Features guide F2/F9/F11). Defaults live in
 * App\Models\PropertySettings::DEFAULTS, the single source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->unique()->constrained()->cascadeOnDelete();

            // Rent and due dates (S5, SC-33, SC-04, SC-29)
            $table->string('due_date_policy', 20);
            $table->unsignedTinyInteger('common_due_day')->nullable();
            $table->string('partial_period_handling', 20);
            $table->boolean('activation_required');
            $table->string('deposit_rule', 20);
            $table->unsignedBigInteger('deposit_fixed_centavos')->nullable();
            $table->unsignedSmallInteger('price_change_notice_days');
            $table->unsignedBigInteger('overstay_daily_centavos')->nullable(); // null = monthly rent ÷ 30

            // Notice and move-out (SC-10, SC-28)
            $table->unsignedSmallInteger('minimum_notice_days')->nullable();
            $table->unsignedSmallInteger('suggested_notice_days');
            $table->string('short_notice_consequence', 30);
            $table->unsignedBigInteger('short_notice_fee_centavos');
            $table->string('final_utility_handling', 30);
            $table->unsignedBigInteger('final_utility_holdback_centavos')->nullable();

            // Utilities (S3, S6, SC-20)
            $table->string('split_manager', 20);
            $table->string('split_method', 30);
            $table->string('utility_due_rule', 30);
            $table->unsignedSmallInteger('utility_due_days');

            // Payments and arrears (S8)
            $table->unsignedSmallInteger('grace_days');
            $table->boolean('remind_before_due');
            $table->unsignedSmallInteger('remind_before_days');
            $table->boolean('remind_on_due');
            $table->boolean('remind_overdue');
            $table->json('overdue_reminder_days');
            $table->boolean('mark_late_enabled');
            $table->unsignedSmallInteger('mark_late_day');
            $table->string('late_fee_type', 20);
            $table->unsignedBigInteger('late_fee_fixed_centavos')->nullable();
            $table->unsignedSmallInteger('late_fee_basis_points')->nullable(); // 500 = 5%
            $table->boolean('arrears_enabled');
            $table->unsignedSmallInteger('arrears_days');
            $table->unsignedSmallInteger('notice_to_vacate_min_days');
            $table->unsignedSmallInteger('notice_to_vacate_end_days');
            $table->boolean('early_warning_enabled');
            $table->unsignedSmallInteger('early_warning_late_count');
            $table->unsignedSmallInteger('early_warning_window_periods');
            $table->unsignedSmallInteger('payment_promise_max_days');
            $table->string('correction_credit_handling', 20);

            // Booking (F2)
            $table->unsignedSmallInteger('reservation_expiry_days');

            // Curfew (F9)
            $table->time('curfew_time')->nullable();
            $table->unsignedSmallInteger('curfew_reminder_minutes');

            // Visitors (F11)
            $table->boolean('overnight_requires_approval');
            $table->unsignedSmallInteger('overnight_limit_per_month');
            $table->boolean('extra_occupant_fee_enabled');
            $table->unsignedBigInteger('extra_occupant_fee_centavos')->nullable();
            $table->time('visitor_hours_start')->nullable();
            $table->time('visitor_hours_end')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_settings');
    }
};

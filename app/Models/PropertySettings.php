<?php

namespace App\Models;

use App\Enums\CorrectionCreditHandling;
use App\Enums\DepositRule;
use App\Enums\DueDatePolicy;
use App\Enums\FinalUtilityHandling;
use App\Enums\LateFeeType;
use App\Enums\PartialPeriodHandling;
use App\Enums\ShortNoticeConsequence;
use App\Enums\SplitManager;
use App\Enums\SplitMethod;
use App\Enums\UtilityDueRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every owner setting for one property. DEFAULTS is the single source of the
 * guides' defaults (Billing guide "Owner settings reference" and S8 "Default
 * escalation timings"; Features guide F2, F9, F11, confirmed billing rules).
 */
class PropertySettings extends Model
{
    public const DEFAULTS = [
        // Rent and due dates
        'due_date_policy' => 'anniversary',            // S5: anniversary (default)
        'common_due_day' => null,
        'partial_period_handling' => 'prorated',       // SC-21
        'activation_required' => true,                 // SC-33: deposit + first rent before Active
        'deposit_rule' => 'one_month_rent',            // F5: default one month's rent
        'deposit_fixed_centavos' => null,
        'price_change_notice_days' => 30,              // F5: default 30 days
        'overstay_daily_centavos' => null,             // SC-29: null = monthly rent ÷ 30

        // Notice and move-out
        'minimum_notice_days' => null,                 // S8: no minimum
        'suggested_notice_days' => 7,                  // S8: 7 days suggested to boarders
        'short_notice_consequence' => 'none',
        'short_notice_fee_centavos' => 0,              // S8: ₱0
        'final_utility_handling' => 'no_holdback',     // SC-28: owner sets (our default)
        'final_utility_holdback_centavos' => null,

        // Utilities
        'split_manager' => 'owner',                    // S3: owner
        'split_method' => 'occupant_days',             // F5: default occupant-days
        'utility_due_rule' => 'days_after_issue',      // SC-20
        'utility_due_days' => 7,                       // N = 7

        // Payments and arrears (S8 default escalation timings)
        'grace_days' => 3,                             // days 1–3 overdue, no penalty
        'remind_before_due' => true,
        'remind_before_days' => 3,                     // 3 days before the due date
        'remind_on_due' => true,
        'remind_overdue' => true,
        'overdue_reminder_days' => [1, 3],             // reminder on day 1 and day 3
        'mark_late_enabled' => true,
        'mark_late_day' => 4,                          // day 4: marked late, first notice
        'late_fee_type' => 'off',                      // late fee off
        'late_fee_fixed_centavos' => null,
        'late_fee_basis_points' => null,
        'arrears_enabled' => false,                    // Level 4 off
        'arrears_days' => 15,                          // suggested 15 days overdue
        'notice_to_vacate_min_days' => 30,             // manual only; suggested ≥ 30 days
        'notice_to_vacate_end_days' => 15,             // end date 15 days after the notice
        'early_warning_enabled' => true,               // 2 late payments in the last 6 periods
        'early_warning_late_count' => 2,
        'early_warning_window_periods' => 6,
        'payment_promise_max_days' => 14,
        'correction_credit_handling' => 'roll_forward', // SC-14: roll forward by default

        // Booking (F2)
        'reservation_expiry_days' => 7,

        // Curfew (F9)
        'curfew_time' => null,                         // off
        'curfew_reminder_minutes' => 30,

        // Visitors (F11)
        'overnight_requires_approval' => true,
        'overnight_limit_per_month' => 3,
        'extra_occupant_fee_enabled' => false,
        'extra_occupant_fee_centavos' => null,
        'visitor_hours_start' => null,
        'visitor_hours_end' => null,
    ];

    /**
     * Settings of features left out of the first release (System Design guide:
     * anniversary due dates only, no proration, late fees, holdback, formal
     * demand or notice-to-vacate automation, promises, early warning, lease
     * holders). The columns stay and keep their defaults, but the API neither
     * shows nor accepts them.
     */
    public const RETIRED = [
        'due_date_policy', 'common_due_day', 'partial_period_handling', 'overstay_daily_centavos',
        'short_notice_consequence', 'short_notice_fee_centavos', 'final_utility_handling',
        'final_utility_holdback_centavos', 'split_manager', 'utility_due_rule', 'late_fee_type',
        'late_fee_fixed_centavos', 'late_fee_basis_points', 'arrears_enabled', 'arrears_days',
        'notice_to_vacate_min_days', 'notice_to_vacate_end_days', 'early_warning_enabled',
        'early_warning_late_count', 'early_warning_window_periods', 'payment_promise_max_days',
    ];

    /** Groups shown in the app and reset together. */
    public const SECTIONS = [
        'rent' => ['activation_required', 'deposit_rule', 'deposit_fixed_centavos', 'price_change_notice_days'],
        'move_out' => ['minimum_notice_days', 'suggested_notice_days'],
        'utilities' => ['split_method', 'utility_due_days'],
        'payments' => ['grace_days', 'remind_before_due', 'remind_before_days', 'remind_on_due', 'remind_overdue',
            'overdue_reminder_days', 'mark_late_enabled', 'mark_late_day', 'correction_credit_handling'],
        'booking' => ['reservation_expiry_days'],
        'curfew' => ['curfew_time', 'curfew_reminder_minutes'],
        'visitors' => ['overnight_requires_approval', 'overnight_limit_per_month', 'extra_occupant_fee_enabled',
            'extra_occupant_fee_centavos', 'visitor_hours_start', 'visitor_hours_end'],
    ];

    /** @return list<string> the settings owners can see and change */
    public static function activeFields(): array
    {
        return array_values(array_diff(array_keys(self::DEFAULTS), self::RETIRED));
    }

    protected $guarded = ['id', 'property_id'];

    protected $attributes = [];

    public function __construct(array $attributes = [])
    {
        // New rows start from the guide's defaults.
        $this->attributes = array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, self::DEFAULTS);
        parent::__construct($attributes);
    }

    protected function casts(): array
    {
        return [
            'due_date_policy' => DueDatePolicy::class,
            'partial_period_handling' => PartialPeriodHandling::class,
            'deposit_rule' => DepositRule::class,
            'short_notice_consequence' => ShortNoticeConsequence::class,
            'final_utility_handling' => FinalUtilityHandling::class,
            'split_manager' => SplitManager::class,
            'split_method' => SplitMethod::class,
            'utility_due_rule' => UtilityDueRule::class,
            'late_fee_type' => LateFeeType::class,
            'correction_credit_handling' => CorrectionCreditHandling::class,
            'overdue_reminder_days' => 'array',
            'activation_required' => 'boolean',
            'remind_before_due' => 'boolean',
            'remind_on_due' => 'boolean',
            'remind_overdue' => 'boolean',
            'mark_late_enabled' => 'boolean',
            'arrears_enabled' => 'boolean',
            'early_warning_enabled' => 'boolean',
            'overnight_requires_approval' => 'boolean',
            'extra_occupant_fee_enabled' => 'boolean',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @param  list<string>  $sections */
    public function resetSections(array $sections): void
    {
        foreach ($sections as $section) {
            foreach (self::SECTIONS[$section] as $field) {
                $this->{$field} = self::DEFAULTS[$field];
            }
        }
    }
}

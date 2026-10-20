<?php

namespace App\Http\Controllers\Api\V1\Properties;

use App\Enums\AuditEvent;
use App\Enums\CorrectionCreditHandling;
use App\Enums\DepositRule;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Properties\PropertySettingsResource;
use App\Http\Responses\ApiResponse;
use App\Models\Property;
use App\Models\PropertySettings;
use App\Services\Audit\AuditDiff;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Properties\HouseRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Owner settings per property, with the guides' defaults.
 *
 * @group Property settings
 */
class SettingsController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly AuditService $audit, private readonly HouseRuleService $houseRules) {}

    /**
     * Get settings
     *
     * Values, the guide defaults, and the section each belongs to.
     */
    public function show(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::View);

        return ApiResponse::ok(new PropertySettingsResource($this->settings($property)));
    }

    /**
     * Update settings
     *
     * Send only the fields that change. Money is in centavos; percentages in
     * basis points (500 = 5%); times as HH:MM.
     */
    public function update(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ManageRules);

        $settings = $this->settings($property);
        $data = $request->validate($this->rules());

        $merged = array_merge($this->plain($settings), $data);
        $this->validateCombination($merged);

        $before = $this->plain($settings);
        $settings->fill($data)->save();

        $this->record($property, $before, $settings);
        $rules = $this->syncHouseRules($request, $property, $before, $settings);

        return ApiResponse::ok(new PropertySettingsResource($settings), 'Settings saved.'.$rules);
    }

    /**
     * Reset sections to the guide defaults
     */
    public function reset(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ManageRules);

        $data = $request->validate([
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => [Rule::in(array_keys(PropertySettings::SECTIONS))],
        ]);

        $settings = $this->settings($property);
        $before = $this->plain($settings);
        $settings->resetSections($data['sections']);
        $settings->save();

        $this->record($property, $before, $settings, 'Reset to defaults: '.implode(', ', $data['sections']));
        $rules = $this->syncHouseRules($request, $property, $before, $settings);

        return ApiResponse::ok(new PropertySettingsResource($settings), 'Back to the default settings.'.$rules);
    }

    /**
     * Curfew or visitor settings changed: the house rules get a new version
     * with the new lines (D2, D4 in D3). Returns a sentence for the message.
     */
    private function syncHouseRules(Request $request, Property $property, array $before, PropertySettings $settings): string
    {
        $keys = [...PropertySettings::SECTIONS['curfew'], ...PropertySettings::SECTIONS['visitors']];
        $after = $this->plain($settings);
        if (array_intersect_key($before, array_flip($keys)) == array_intersect_key($after, array_flip($keys))) {
            return '';
        }
        $version = $this->houseRules->syncAutoLines($property->setRelation('settings', $settings), $request->user());

        return $version ? " House rules version {$version->version} published; boarders are asked to accept it." : '';
    }

    private function settings(Property $property): PropertySettings
    {
        return $property->settings ?? $property->settings()->create();
    }

    private function record(Property $property, array $before, PropertySettings $settings, ?string $note = null): void
    {
        $changes = AuditDiff::between($before, $this->plain($settings));
        if ($changes !== []) {
            $this->audit->record(AuditEvent::SettingsChanged, $property, $changes, owner: $property->owner,
                note: $note ? "{$property->name} · {$note}" : $property->name);
        }
    }

    /** @return array<string, mixed> */
    private function plain(PropertySettings $settings): array
    {
        return (new PropertySettingsResource($settings))->toArray(request())['values'];
    }

    /** Rules that depend on other settings (e.g. a fixed deposit needs an amount). */
    private function validateCombination(array $s): void
    {
        $errors = [];
        if ($s['deposit_rule'] === 'fixed_amount' && $s['deposit_fixed_centavos'] === null) {
            $errors['deposit_fixed_centavos'] = 'Enter the deposit amount.';
        }
        if ($s['extra_occupant_fee_enabled'] && empty($s['extra_occupant_fee_centavos'])) {
            $errors['extra_occupant_fee_centavos'] = 'Enter the extra-occupant fee.';
        }
        if (($s['visitor_hours_start'] === null) !== ($s['visitor_hours_end'] === null)) {
            $errors['visitor_hours_end'] = 'Set both the start and end of visitor hours, or neither.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function rules(): array
    {
        $money = ['nullable', 'integer', 'min:0', 'max:100000000'];
        $days = fn (int $max) => ['integer', 'min:0', 'max:'.$max];

        return [
            'activation_required' => ['sometimes', 'boolean'],
            'deposit_rule' => ['sometimes', Rule::enum(DepositRule::class)],
            'deposit_fixed_centavos' => ['sometimes', ...$money],
            'price_change_notice_days' => ['sometimes', ...$days(365)],

            'minimum_notice_days' => ['sometimes', 'nullable', ...$days(180)],
            'suggested_notice_days' => ['sometimes', ...$days(180)],

            'split_method' => ['sometimes', Rule::in(['equal', 'occupant_days', 'weights', 'custom'])],
            'utility_due_days' => ['sometimes', 'integer', 'min:1', 'max:60'],

            'grace_days' => ['sometimes', ...$days(60)],
            'remind_before_due' => ['sometimes', 'boolean'],
            'remind_before_days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'remind_on_due' => ['sometimes', 'boolean'],
            'remind_overdue' => ['sometimes', 'boolean'],
            'overdue_reminder_days' => ['sometimes', 'array', 'max:10'],
            'overdue_reminder_days.*' => ['integer', 'min:1', 'max:90', 'distinct'],
            'mark_late_enabled' => ['sometimes', 'boolean'],
            'correction_credit_handling' => ['sometimes', Rule::enum(CorrectionCreditHandling::class)],

            'reservation_expiry_days' => ['sometimes', 'integer', 'min:1', 'max:60'],

            'curfew_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'curfew_reminder_minutes' => ['sometimes', 'integer', 'min:0', 'max:240'],

            'overnight_requires_approval' => ['sometimes', 'boolean'],
            'overnight_limit_per_month' => ['sometimes', 'integer', 'min:0', 'max:31'],
            'extra_occupant_fee_enabled' => ['sometimes', 'boolean'],
            'extra_occupant_fee_centavos' => ['sometimes', ...$money],
            'visitor_hours_start' => ['sometimes', 'nullable', 'date_format:H:i'],
            'visitor_hours_end' => ['sometimes', 'nullable', 'date_format:H:i'],
        ];
    }
}

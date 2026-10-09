<?php

namespace App\Http\Requests\Tenancies;

use App\Authorization\PropertyAccess;
use App\Enums\DiscountKind;
use App\Enums\PaymentMethod;
use App\Enums\PropertyAbility;
use App\Models\Property;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving a boarder in from their reservation. Who may do it (the property's
 * `manage_tenancies` ability) is checked first, in authorize().
 */
class MoveInRequest extends FormRequest
{
    private const PHONE = '/^[0-9+\-\s()]{7,20}$/';

    /**
     * Checked before the details are validated, so someone with no relation
     * to the property learns nothing (404), and someone who can see it but
     * not move people in gets 403.
     */
    public function authorize(): bool
    {
        $user = $this->user();
        $property = $this->targetProperty();

        abort_unless(PropertyAccess::can($user, PropertyAbility::View, $property), 404);
        abort_unless(PropertyAccess::can($user, PropertyAbility::ManageTenancies, $property), 403, 'You do not have permission to do this for this property.');

        return true;
    }

    /** The property the move-in is for: the reservation's. */
    protected function targetProperty(): Property
    {
        return $this->route('application')->property;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $payment = fn (string $key) => [
            $key => ['sometimes', 'nullable', 'array'],
            "{$key}.amount_centavos" => ["required_with:{$key}", 'integer', 'min:0', 'max:100000000'],
            "{$key}.method" => ["required_with:{$key}", Rule::enum(PaymentMethod::class)],
            "{$key}.received_on" => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            "{$key}.reference" => ['sometimes', 'nullable', 'string', 'max:100'],
        ];

        return [
            'moved_in_on' => ['required', 'date_format:Y-m-d'],
            'emergency_contact' => ['required', 'array'],
            'emergency_contact.name' => ['required', 'string', 'max:120'],
            'emergency_contact.relationship' => ['sometimes', 'nullable', 'string', 'max:50'],
            'emergency_contact.phone' => ['required', 'string', 'regex:'.self::PHONE],

            'discount' => ['sometimes', 'nullable', 'array'],
            'discount.kind' => ['required_with:discount', Rule::enum(DiscountKind::class)],
            'discount.value' => ['required_with:discount', 'integer', 'min:1', 'max:100000000'],

            ...$payment('deposit'),
            ...$payment('first_rent'),
            'override_reason' => ['sometimes', 'nullable', 'string', 'max:500'],

            'leader_consent' => ['sometimes', 'boolean'],
            'make_leader' => ['sometimes', 'boolean'],
            'occupants' => ['sometimes', 'nullable', 'array', 'max:49'],
            'occupants.*.name' => ['required', 'string', 'max:120'],
            'occupants.*.contact_phone' => ['sometimes', 'nullable', 'string', 'regex:'.self::PHONE],
            'occupants.*.emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'occupants.*.emergency_contact_relationship' => ['sometimes', 'nullable', 'string', 'max:50'],
            'occupants.*.emergency_contact_phone' => ['nullable', 'required_with:occupants.*.emergency_contact_name', 'string', 'regex:'.self::PHONE],
        ];
    }

    public function messages(): array
    {
        return [
            'emergency_contact.phone.regex' => 'Enter a valid phone number.',
            'emergency_contact.name.required' => 'Add an emergency contact for the tenant.',
            'emergency_contact.phone.required' => 'Add an emergency contact number for the tenant.',
        ];
    }

    /**
     * What the tenancy service works with: unset optional parts dropped, so
     * "nothing sent" and "sent as null" mean the same.
     *
     * @return array<string, mixed>
     */
    public function moveInData(): array
    {
        $data = $this->safe()->except(['email', 'unit_id']);

        return array_filter($data, fn ($v) => $v !== null);
    }
}

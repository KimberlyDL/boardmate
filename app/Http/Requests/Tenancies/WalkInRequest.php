<?php

namespace App\Http\Requests\Tenancies;

use App\Models\Property;
use App\Models\User;

/**
 * Moving in someone who never applied: the same details as a reservation
 * move-in, plus the unit and the tenant's account email. `existing_tenant`
 * adds someone already living here before the place used DormLink.
 */
class WalkInRequest extends MoveInRequest
{
    protected function targetProperty(): Property
    {
        return $this->route('property');
    }

    /** The emergency contact in the profile of the account being moved in. */
    protected function savedEmergencyContact(): ?array
    {
        $email = $this->input('email');

        return is_string($email) && $email !== ''
            ? self::fromProfile(User::where('email', mb_strtolower($email))->first()?->boarderProfile)
            : null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'integer'],
            'email' => ['required', 'email', 'max:255'],
            // Already living here before the place used DormLink: see TenancyService.
            'existing_tenant' => ['sometimes', 'boolean'],
            // What an existing tenant still owes from before DormLink: one one-time charge.
            'opening_balance_centavos' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000'],
            ...parent::rules(),
        ];
    }
}

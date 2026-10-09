<?php

namespace App\Http\Requests\Tenancies;

use App\Models\Property;

/**
 * Moving in someone who never applied: the same details as a reservation
 * move-in, plus the unit and the tenant's account email.
 */
class WalkInRequest extends MoveInRequest
{
    protected function targetProperty(): Property
    {
        return $this->route('property');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'integer'],
            'email' => ['required', 'email', 'max:255'],
            ...parent::rules(),
        ];
    }
}

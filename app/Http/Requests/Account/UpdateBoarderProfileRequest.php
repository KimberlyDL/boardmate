<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBoarderProfileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'emergency_contact_name' => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'required_with:emergency_contact_name', 'string', 'regex:/^[0-9+\-\s()]{7,20}$/'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'emergency_contact_phone.regex' => 'Enter a valid phone number.',
            'emergency_contact_phone.required_with' => 'Add a phone number for your emergency contact.',
        ];
    }
}

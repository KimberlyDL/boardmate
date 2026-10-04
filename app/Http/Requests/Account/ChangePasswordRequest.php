<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Change password, or set a first password for accounts created with Google
 * (they have none, so no current password is asked).
 */
class ChangePasswordRequest extends FormRequest
{
    public function rules(): array
    {
        // No user when Scribe reads the rules for the API docs.
        $hasPassword = $this->user()?->hasPassword() ?? true;

        return [
            'current_password' => $hasPassword ? ['required', 'current_password:sanctum'] : ['nullable'],
            'password' => array_filter([
                'required',
                'confirmed',
                $hasPassword ? 'different:current_password' : null,
                Password::min(8)->letters()->numbers(),
            ]),
        ];
    }
}

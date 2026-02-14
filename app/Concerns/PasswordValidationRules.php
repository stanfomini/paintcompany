<?php

namespace App\Concerns;

use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /**
     * Get the validation rules used to validate passwords.
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::default(), 'confirmed'];
    }

    /**
     * Get the validation rules used to validate the current password.
     *
     * @return array<int, \Illuminate\Contracts\Validation\Rule|array<mixed>|string>
     */
    protected function currentPasswordRules(?string $guard = null): array
    {
        $rule = $guard ? "current_password:{$guard}" : 'current_password';

        return ['required', 'string', $rule];
    }
}

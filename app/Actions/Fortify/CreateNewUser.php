<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * Registration is disabled (Features::registration() commented out in config/fortify.php).
     * This action exists as a Fortify contract requirement but should never be called.
     * Safety: explicitly aborts to prevent accidental user creation if registration is re-enabled.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        // Registration is disabled - abort if this is ever called
        abort(403, 'Registration is disabled. Users must be invited.');
    }
}

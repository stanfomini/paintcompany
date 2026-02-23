<?php

namespace Database\Factories;

use App\Models\EmailAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmailThread>
 */
class EmailThreadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'subject' => fake()->sentence(),
            'original_message_id' => '<'.fake()->uuid().'@'.config('email.domain').'>',
            'email_account_id' => EmailAccount::factory(),
            'last_message_at' => now(),
        ];
    }
}

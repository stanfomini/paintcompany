<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmailCrmLink>
 */
class EmailCrmLinkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'email_message_id' => EmailMessage::factory(),
            'linkable_type' => Customer::class,
            'linkable_id' => Customer::factory(),
            'link_type' => 'auto',
            'linked_by' => null,
        ];
    }

    public function manual(): static
    {
        return $this->state(fn (array $attributes) => [
            'link_type' => 'manual',
        ]);
    }
}

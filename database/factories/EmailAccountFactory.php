<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EmailAccount>
 */
class EmailAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->internal(),
            'address' => fake()->unique()->userName(),
            'display_name' => fake()->name(),
            'signature_html' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withSignature(): static
    {
        return $this->state(fn (array $attributes) => [
            'signature_html' => '<p>Best regards,<br>'.fake()->name().'</p>',
        ]);
    }
}

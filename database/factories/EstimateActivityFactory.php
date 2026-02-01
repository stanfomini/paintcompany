<?php

namespace Database\Factories;

use App\Models\Estimate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EstimateActivity>
 */
class EstimateActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'estimate_id' => Estimate::factory(),
            'user_id' => fake()->optional(0.7)->randomElement([User::factory()->internal()]),
            'activity_type' => fake()->randomElement(['viewed', 'sent', 'accepted', 'rejected', 'option_toggled', 'note_added', 'signature_added', 'photo_added']),
            'description' => fake()->optional(0.5)->sentence(),
            'metadata' => null,
            'ip_address' => fake()->ipv4(),
        ];
    }

    /**
     * Associate with a specific estimate.
     */
    public function forEstimate(Estimate $estimate): static
    {
        return $this->state(fn (array $attributes) => [
            'estimate_id' => $estimate->id,
        ]);
    }

    /**
     * Set the activity type to viewed.
     */
    public function viewed(): static
    {
        return $this->state(fn (array $attributes) => [
            'activity_type' => 'viewed',
            'description' => 'Estimate was viewed',
            'metadata' => [
                'referrer' => fake()->optional(0.5)->url(),
            ],
        ]);
    }

    /**
     * Set the activity type to sent.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'activity_type' => 'sent',
            'description' => 'Estimate was sent to customer',
            'metadata' => [
                'recipient_email' => fake()->safeEmail(),
            ],
        ]);
    }

    /**
     * Set the activity type to accepted.
     */
    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => [
            'activity_type' => 'accepted',
            'description' => 'Estimate was accepted',
            'metadata' => [
                'accepted_by' => fake()->name(),
            ],
        ]);
    }

    /**
     * Set the activity type to rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'activity_type' => 'rejected',
            'description' => 'Estimate was rejected',
            'metadata' => [
                'reason' => fake()->optional(0.7)->sentence(),
            ],
        ]);
    }

    /**
     * Set the activity type to option_toggled.
     */
    public function optionToggled(): static
    {
        return $this->state(fn (array $attributes) => [
            'activity_type' => 'option_toggled',
            'description' => 'Line item option was toggled',
            'metadata' => [
                'item_id' => fake()->randomNumber(3),
                'item_name' => fake()->words(3, true),
                'enabled' => fake()->boolean(),
            ],
        ]);
    }

    /**
     * Set the activity type to note_added.
     */
    public function noteAdded(): static
    {
        return $this->state(fn (array $attributes) => [
            'activity_type' => 'note_added',
            'description' => 'A note was added',
            'metadata' => [
                'note_preview' => fake()->sentence(),
            ],
        ]);
    }

    /**
     * Set the activity type to signature_added.
     */
    public function signatureAdded(): static
    {
        return $this->state(fn (array $attributes) => [
            'activity_type' => 'signature_added',
            'description' => 'Estimate was signed',
            'metadata' => [
                'signer_name' => fake()->name(),
                'signer_email' => fake()->safeEmail(),
                'signature_type' => fake()->randomElement(['drawn', 'typed']),
            ],
        ]);
    }

    /**
     * Set the activity type to photo_added.
     */
    public function photoAdded(): static
    {
        return $this->state(fn (array $attributes) => [
            'activity_type' => 'photo_added',
            'description' => 'A photo was added',
            'metadata' => [
                'photo_count' => fake()->numberBetween(1, 5),
            ],
        ]);
    }

    /**
     * Associate with a specific user.
     */
    public function byUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Set as anonymous (no user).
     */
    public function anonymous(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }
}

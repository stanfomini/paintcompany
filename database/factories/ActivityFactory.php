<?php

namespace Database\Factories;

use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Activity>
 */
class ActivityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->internal(),
            'subject_type' => Deal::class,
            'subject_id' => Deal::factory(),
            'type' => fake()->randomElement(['task', 'note', 'call', 'email', 'meeting', 'other']),
            'title' => fake()->sentence(4),
            'description' => fake()->optional(0.5)->paragraph(),
            'is_completed' => false,
            'due_at' => fake()->optional(0.7)->dateTimeBetween('now', '+2 weeks'),
        ];
    }

    /**
     * Set as a task.
     */
    public function task(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'task',
            'title' => fake()->randomElement(['Follow up with customer', 'Send estimate', 'Schedule site visit', 'Prepare materials', 'Complete job']),
        ]);
    }

    /**
     * Set as a note.
     */
    public function note(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'note',
            'title' => 'Note',
            'description' => fake()->paragraph(),
            'due_at' => null,
        ]);
    }

    /**
     * Set as a call.
     */
    public function call(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'call',
            'title' => fake()->randomElement(['Initial consultation call', 'Follow-up call', 'Pricing discussion', 'Scheduling call']),
        ]);
    }

    /**
     * Set as an email.
     */
    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'email',
            'title' => fake()->randomElement(['Sent estimate', 'Sent contract', 'Project update', 'Thank you email']),
        ]);
    }

    /**
     * Set as a meeting.
     */
    public function meeting(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'meeting',
            'title' => fake()->randomElement(['Site visit', 'Project kickoff', 'Final walkthrough', 'Color consultation']),
        ]);
    }

    /**
     * Set as completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_completed' => true,
            'completed_at' => fake()->dateTimeBetween('-1 week', 'now'),
        ]);
    }

    /**
     * Set as overdue.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_completed' => false,
            'due_at' => fake()->dateTimeBetween('-1 week', '-1 day'),
        ]);
    }

    /**
     * Set as due today.
     */
    public function dueToday(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_completed' => false,
            'due_at' => now()->setTime(fake()->numberBetween(9, 17), 0),
        ]);
    }

    /**
     * Associate with a specific deal.
     */
    public function forDeal(Deal $deal): static
    {
        return $this->state(fn (array $attributes) => [
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
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
}

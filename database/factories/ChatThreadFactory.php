<?php

namespace Database\Factories;

use App\Models\ChatThread;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatThread>
 */
class ChatThreadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => ChatThread::TYPE_DIRECT,
            'subject' => fake()->optional(0.5)->sentence(3),
            'is_archived' => false,
            'last_message_at' => fake()->optional(0.7)->dateTimeBetween('-1 week', 'now'),
        ];
    }

    /**
     * Set thread type to deal.
     */
    public function forDeal(?Deal $deal = null): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ChatThread::TYPE_DEAL,
            'deal_id' => $deal?->id ?? Deal::factory(),
            'subject' => null, // Use deal title as display name
        ]);
    }

    /**
     * Set thread type to direct.
     */
    public function direct(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ChatThread::TYPE_DIRECT,
            'deal_id' => null,
        ]);
    }

    /**
     * Set thread type to support.
     */
    public function support(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ChatThread::TYPE_SUPPORT,
            'deal_id' => null,
        ]);
    }

    /**
     * Mark thread as archived.
     */
    public function archived(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_archived' => true,
        ]);
    }

    /**
     * Set custom subject.
     */
    public function withSubject(string $subject): static
    {
        return $this->state(fn (array $attributes) => [
            'subject' => $subject,
        ]);
    }

    /**
     * Add participants after creating the thread.
     */
    public function withParticipants(array $users): static
    {
        return $this->afterCreating(function (ChatThread $thread) use ($users) {
            foreach ($users as $user) {
                $thread->addParticipant($user);
            }
        });
    }

    /**
     * Create a thread with two participants.
     */
    public function betweenUsers(User $user1, User $user2): static
    {
        return $this->afterCreating(function (ChatThread $thread) use ($user1, $user2) {
            $thread->addParticipant($user1);
            $thread->addParticipant($user2);
        });
    }
}

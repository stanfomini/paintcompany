<?php

namespace Database\Factories;

use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatParticipant>
 */
class ChatParticipantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'thread_id' => ChatThread::factory(),
            'user_id' => User::factory(),
            'last_read_at' => fake()->optional(0.7)->dateTimeBetween('-1 week', 'now'),
            'is_muted' => false,
            'joined_at' => now(),
            'left_at' => null,
        ];
    }

    /**
     * Set specific thread.
     */
    public function forThread(ChatThread $thread): static
    {
        return $this->state(fn (array $attributes) => [
            'thread_id' => $thread->id,
        ]);
    }

    /**
     * Set specific user.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Mark as muted.
     */
    public function muted(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_muted' => true,
        ]);
    }

    /**
     * Mark as having left the thread.
     */
    public function left(): static
    {
        return $this->state(fn (array $attributes) => [
            'left_at' => now(),
        ]);
    }

    /**
     * Mark as unread (no last_read_at).
     */
    public function unread(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_read_at' => null,
        ]);
    }

    /**
     * Mark all messages as read.
     */
    public function allRead(): static
    {
        return $this->state(fn (array $attributes) => [
            'last_read_at' => now(),
        ]);
    }
}

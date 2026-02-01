<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatMessageRead>
 */
class ChatMessageReadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => ChatMessage::factory(),
            'user_id' => User::factory(),
            'read_at' => now(),
        ];
    }

    /**
     * Set specific message.
     */
    public function forMessage(ChatMessage $message): static
    {
        return $this->state(fn (array $attributes) => [
            'message_id' => $message->id,
        ]);
    }

    /**
     * Set specific user.
     */
    public function byUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Set read at a specific time.
     */
    public function readAt(\DateTimeInterface $dateTime): static
    {
        return $this->state(fn (array $attributes) => [
            'read_at' => $dateTime,
        ]);
    }
}

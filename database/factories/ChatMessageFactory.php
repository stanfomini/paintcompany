<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ChatMessage>
 */
class ChatMessageFactory extends Factory
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
            'content' => fake()->paragraph(),
            'type' => ChatMessage::TYPE_TEXT,
            'is_internal' => false,
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
    public function fromUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
        ]);
    }

    /**
     * Set as text message type.
     */
    public function text(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ChatMessage::TYPE_TEXT,
        ]);
    }

    /**
     * Set as file message type.
     */
    public function file(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ChatMessage::TYPE_FILE,
        ]);
    }

    /**
     * Set as image message type.
     */
    public function image(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ChatMessage::TYPE_IMAGE,
        ]);
    }

    /**
     * Set as system message type.
     */
    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ChatMessage::TYPE_SYSTEM,
            'content' => fake()->sentence(),
        ]);
    }

    /**
     * Mark as internal (staff-only).
     */
    public function internal(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_internal' => true,
        ]);
    }

    /**
     * Mark as public (visible to all).
     */
    public function public(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_internal' => false,
        ]);
    }

    /**
     * Set as a reply to another message.
     */
    public function replyTo(ChatMessage $message): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $message->id,
            'thread_id' => $message->thread_id,
        ]);
    }

    /**
     * Mark as edited.
     */
    public function edited(): static
    {
        return $this->state(fn (array $attributes) => [
            'edited_at' => now(),
        ]);
    }

    /**
     * Set custom content.
     */
    public function withContent(string $content): static
    {
        return $this->state(fn (array $attributes) => [
            'content' => $content,
        ]);
    }

    /**
     * Set custom metadata.
     */
    public function withMetadata(array $metadata): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => $metadata,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\ChatThread;
use App\Models\GuestChatSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GuestChatSession>
 */
class GuestChatSessionFactory extends Factory
{
    protected $model = GuestChatSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid()->toString(),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'ip_address' => fake()->ipv4(),
            'city' => fake()->city(),
            'user_agent' => fake()->userAgent(),
            'chat_thread_id' => ChatThread::factory()->support(),
            'status' => 'active',
            'started_at' => now(),
        ];
    }

    public function ended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ended',
            'ended_at' => now(),
        ]);
    }

    public function converted(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'converted',
            'user_id' => $user?->id ?? User::factory(),
        ]);
    }

    public function forThread(ChatThread $thread): static
    {
        return $this->state(fn (array $attributes) => [
            'chat_thread_id' => $thread->id,
        ]);
    }
}

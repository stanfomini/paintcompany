<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExternalApiSource>
 */
class ExternalApiSourceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'token' => (string) Str::uuid(),
            'url' => 'https://api.example.com/data',
            'http_method' => 'GET',
            'is_active' => true,
            'auth_type' => 'none',
            'poll_interval' => 'every_5_min',
            'created_by' => User::factory()->internal(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function withBearerAuth(string $token = 'test-bearer-token'): static
    {
        return $this->state(fn () => [
            'auth_type' => 'bearer',
            'auth_value' => $token,
        ]);
    }

    public function withApiKeyAuth(string $header = 'X-Api-Key', string $value = 'test-api-key'): static
    {
        return $this->state(fn () => [
            'auth_type' => 'api_key',
            'auth_header' => $header,
            'auth_value' => $value,
        ]);
    }

    public function withLastResponse(array $response = ['data' => [['id' => 1, 'name' => 'Test']]]): static
    {
        return $this->state(fn () => [
            'last_response' => $response,
            'last_success_at' => now(),
        ]);
    }
}

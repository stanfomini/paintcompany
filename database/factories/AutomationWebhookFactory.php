<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AutomationWebhook>
 */
class AutomationWebhookFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'token' => (string) Str::uuid(),
            'is_active' => true,
            'auth_type' => 'none',
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

    public function withQueryParamAuth(string $param = 'api_key', string $value = 'test-api-key'): static
    {
        return $this->state(fn () => [
            'auth_type' => 'query_param',
            'auth_header' => $param,
            'auth_value' => $value,
        ]);
    }
}

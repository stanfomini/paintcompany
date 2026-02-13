<?php

namespace Database\Factories;

use App\Models\LeadSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    public function definition(): array
    {
        return [
            'lead_source_id' => LeadSource::factory()->webhook(),
            'token' => (string) Str::uuid(),
            'is_active' => true,
            'auth_type' => 'none',
            'auth_header' => null,
            'auth_value' => null,
            'content_type' => 'json',
            'sample_payload' => null,
            'last_payload_at' => null,
            'total_leads' => 0,
            'default_assigned_to' => null,
            'created_by' => User::factory()->internal(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    public function withApiKeyAuth(string $header = 'X-Api-Key', string $value = 'test-secret'): static
    {
        return $this->state(fn () => [
            'auth_type' => 'api_key',
            'auth_header' => $header,
            'auth_value' => $value,
        ]);
    }

    public function withBearerAuth(string $token = 'test-bearer-token'): static
    {
        return $this->state(fn () => [
            'auth_type' => 'bearer',
            'auth_value' => $token,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn () => [
            'default_assigned_to' => $user->id,
        ]);
    }

    public function createdBy(User $user): static
    {
        return $this->state(fn () => [
            'created_by' => $user->id,
        ]);
    }
}

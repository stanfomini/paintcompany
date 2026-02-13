<?php

namespace Database\Factories;

use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WebhookFieldMapping>
 */
class WebhookFieldMappingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'source_path' => 'data.field',
            'target_model' => 'customer',
            'target_field' => 'first_name',
            'transform' => null,
            'default_value' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function forCustomer(string $sourcePath, string $targetField): static
    {
        return $this->state(fn () => [
            'source_path' => $sourcePath,
            'target_model' => 'customer',
            'target_field' => $targetField,
        ]);
    }

    public function forDeal(string $sourcePath, string $targetField): static
    {
        return $this->state(fn () => [
            'source_path' => $sourcePath,
            'target_model' => 'deal',
            'target_field' => $targetField,
        ]);
    }

    public function withTransform(string $transform): static
    {
        return $this->state(fn () => [
            'transform' => $transform,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\ColorVisualization;
use App\Models\Estimate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ColorVisualization>
 */
class ColorVisualizationFactory extends Factory
{
    protected $model = ColorVisualization::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => null,
            'estimate_id' => null,
            'session_id' => Str::random(40),
            'original_image_path' => 'photos/test-original.jpg',
            'result_image_path' => null,
            'prompt' => 'Paint the walls Benjamin Moore White Dove and the trim Sherwin-Williams Iron Ore',
            'parsed_colors' => null,
            'status' => ColorVisualization::STATUS_PENDING,
            'error_message' => null,
            'ai_provider' => null,
            'disk' => 'photos',
            'lead_name' => null,
            'lead_email' => null,
            'lead_phone' => null,
            'lead_address' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ColorVisualization::STATUS_COMPLETED,
            'result_image_path' => 'photos/visualizations/result.png',
            'parsed_colors' => [
                ['name' => 'White Dove', 'code' => 'OC-17', 'hex' => '#F3EFE0', 'brand' => 'Benjamin Moore'],
            ],
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ColorVisualization::STATUS_PROCESSING,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ColorVisualization::STATUS_FAILED,
            'error_message' => 'Image generation failed: API timeout',
        ]);
    }

    public function withLead(): static
    {
        return $this->state(fn (array $attributes) => [
            'lead_name' => fake()->name(),
            'lead_email' => fake()->safeEmail(),
            'lead_phone' => fake()->phoneNumber(),
            'lead_address' => fake()->address(),
        ]);
    }

    public function forEstimate(?Estimate $estimate = null): static
    {
        return $this->state(fn (array $attributes) => [
            'estimate_id' => $estimate?->id ?? Estimate::factory(),
        ]);
    }

    public function forUser(?User $user = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user?->id ?? User::factory(),
        ]);
    }

    public function withSession(string $sessionId): static
    {
        return $this->state(fn (array $attributes) => [
            'session_id' => $sessionId,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Models\OnboardingChecklist;
use App\Models\OnboardingChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OnboardingChecklistItem>
 */
class OnboardingChecklistItemFactory extends Factory
{
    protected $model = OnboardingChecklistItem::class;

    public function definition(): array
    {
        return [
            'onboarding_checklist_id' => OnboardingChecklist::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'type' => fake()->randomElement(['task', 'document', 'contract', 'training']),
            'is_required' => true,
            'sort_order' => 0,
            'metadata' => null,
        ];
    }

    public function optional(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_required' => false,
        ]);
    }

    public function document(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'document',
        ]);
    }

    public function contract(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'contract',
        ]);
    }

    public function training(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'training',
        ]);
    }

    public function task(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'task',
        ]);
    }
}

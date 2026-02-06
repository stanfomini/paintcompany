<?php

namespace Database\Factories;

use App\Models\RoadmapCategory;
use App\Models\RoadmapItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RoadmapItem>
 */
class RoadmapItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'roadmap_category_id' => RoadmapCategory::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'status' => RoadmapItem::STATUS_NOT_STARTED,
            'notes' => fake()->optional()->sentence(),
            'assigned_to' => null,
            'due_date' => fake()->optional()->dateTimeBetween('now', '+6 months'),
            'completed_at' => null,
            'sort_order' => fake()->numberBetween(0, 20),
            'metadata' => null,
        ];
    }

    public function forCategory(RoadmapCategory $category): static
    {
        return $this->state(fn (array $attributes) => [
            'roadmap_category_id' => $category->id,
        ]);
    }

    public function assignedTo(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_to' => $user->id,
        ]);
    }

    public function notStarted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RoadmapItem::STATUS_NOT_STARTED,
            'completed_at' => null,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RoadmapItem::STATUS_IN_PROGRESS,
            'completed_at' => null,
        ]);
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RoadmapItem::STATUS_DONE,
            'completed_at' => fake()->dateTimeBetween('-1 month', 'now'),
        ]);
    }

    public function overdue(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => RoadmapItem::STATUS_IN_PROGRESS,
            'due_date' => fake()->dateTimeBetween('-2 months', '-1 day'),
            'completed_at' => null,
        ]);
    }

    public function withMetadata(array $metadata): static
    {
        return $this->state(fn (array $attributes) => [
            'metadata' => $metadata,
        ]);
    }
}

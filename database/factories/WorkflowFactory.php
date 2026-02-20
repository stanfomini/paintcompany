<?php

namespace Database\Factories;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Workflow>
 */
class WorkflowFactory extends Factory
{
    protected $model = Workflow::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'is_active' => true,
            'is_system' => false,
            'version' => 1,
            'priority' => 0,
            'user_scope_type' => Workflow::USER_SCOPE_ANY,
            'user_scope_value' => null,
            'service_category_id' => null,
            'created_by' => User::factory()->internal(),
            'published_at' => null,
        ];
    }

    /**
     * Mark the workflow as published.
     */
    public function published(): static
    {
        return $this->state(fn () => [
            'published_at' => now(),
        ]);
    }

    /**
     * Mark the workflow as inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
        ]);
    }

    /**
     * Mark the workflow as a system workflow.
     */
    public function system(): static
    {
        return $this->state(fn () => [
            'is_system' => true,
        ]);
    }

    /**
     * Scope the workflow to a specific user.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_scope_type' => Workflow::USER_SCOPE_SPECIFIC_USER,
            'user_scope_value' => (string) $user->id,
        ]);
    }

    /**
     * Scope the workflow to a specific role.
     */
    public function forRole(string $role): static
    {
        return $this->state(fn () => [
            'user_scope_type' => Workflow::USER_SCOPE_ROLE,
            'user_scope_value' => $role,
        ]);
    }

    /**
     * Scope the workflow to a specific user type.
     */
    public function forUserType(string $userType): static
    {
        return $this->state(fn () => [
            'user_scope_type' => Workflow::USER_SCOPE_USER_TYPE,
            'user_scope_value' => $userType,
        ]);
    }

    /**
     * Scope the workflow to a specific service category.
     */
    public function withServiceCategory(ServiceCategory $category): static
    {
        return $this->state(fn () => [
            'service_category_id' => $category->id,
        ]);
    }
}

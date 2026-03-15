<?php

namespace Database\Factories;

use App\Models\ProjectPreview;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProjectPreview>
 */
class ProjectPreviewFactory extends Factory
{
    protected $model = ProjectPreview::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'public_token' => ProjectPreview::generateSlugToken(),
            'customer_id' => null,
            'phone' => fake()->e164PhoneNumber(),
            'first_name' => fake()->firstName(),
            'status' => 'active',
            'rooms_data' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(['status' => 'active']);
    }

    public function converted(): static
    {
        return $this->state(['status' => 'converted']);
    }

    public function expired(): static
    {
        return $this->state(['status' => 'expired']);
    }

    public function withCustomer(): static
    {
        return $this->state(fn () => [
            'customer_id' => \App\Models\Customer::factory(),
        ]);
    }

    public function withEstimator(): static
    {
        return $this->state(fn () => [
            'estimator_id' => User::factory()->internal(),
        ]);
    }
}

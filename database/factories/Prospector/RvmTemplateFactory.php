<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Company;
use App\Models\Prospector\RvmTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RvmTemplate>
 */
class RvmTemplateFactory extends Factory
{
    protected $model = RvmTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->randomElement(['Intro Voicemail', 'Follow Up', 'Price Drop', 'Just Checking In', 'Appointment Reminder']),
            'audio_path' => 'rvm-templates/'.fake()->uuid().'.mp3',
            'duration_seconds' => fake()->numberBetween(15, 60),
            'is_active' => true,
            'created_by' => User::factory(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }
}

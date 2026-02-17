<?php

namespace Database\Factories;

use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeDocument>
 */
class EmployeeDocumentFactory extends Factory
{
    protected $model = EmployeeDocument::class;

    public function definition(): array
    {
        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'uploaded_by' => User::factory()->internal(),
            'name' => fake()->words(3, true),
            'type' => fake()->randomElement(array_keys(EmployeeDocument::TYPES)),
            'file_path' => 'employee-documents/'.fake()->uuid().'.pdf',
            'status' => 'pending',
            'notes' => fake()->optional()->sentence(),
            'expires_at' => fake()->optional()->dateTimeBetween('+1 month', '+2 years'),
        ];
    }

    public function w9(): static
    {
        return $this->state(fn () => [
            'name' => 'W-9 Form',
            'type' => 'w9',
        ]);
    }

    public function i9(): static
    {
        return $this->state(fn () => [
            'name' => 'I-9 Form',
            'type' => 'i9',
        ]);
    }

    public function license(): static
    {
        return $this->state(fn () => [
            'name' => 'Driver License',
            'type' => 'license',
            'expires_at' => fake()->dateTimeBetween('+6 months', '+4 years'),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'expires_at' => fake()->dateTimeBetween('-1 year', '-1 day'),
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'reviewed_by' => User::factory()->internal(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => 'rejected',
            'reviewed_by' => User::factory()->internal(),
            'reviewed_at' => now(),
            'review_notes' => fake()->sentence(),
        ]);
    }
}

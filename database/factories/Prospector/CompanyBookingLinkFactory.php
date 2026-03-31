<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\Company;
use App\Models\Prospector\CompanyBookingLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyBookingLink>
 */
class CompanyBookingLinkFactory extends Factory
{
    protected $model = CompanyBookingLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->randomElement(['Calendly', 'Estimate Form', 'Book a Call']),
            'url' => fake()->url(),
            'sort_order' => 0,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn () => ['company_id' => $company->id]);
    }
}

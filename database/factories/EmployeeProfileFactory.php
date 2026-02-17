<?php

namespace Database\Factories;

use App\Models\EmployeeProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeProfile>
 */
class EmployeeProfileFactory extends Factory
{
    protected $model = EmployeeProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory()->internal(),
            'employee_number' => 'EMP-'.str_pad(fake()->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'hire_date' => fake()->dateTimeBetween('-2 years', 'now'),
            'employment_status' => 'active',
            'pay_rate' => fake()->randomFloat(2, 15, 45),
            'pay_type' => 'hourly',
            'department' => fake()->randomElement(['painting', 'management', 'sales', 'operations']),
            'job_title' => fake()->randomElement(['Painter', 'Lead Painter', 'Project Manager', 'Estimator', 'Sales Rep']),
            'date_of_birth' => fake()->dateTimeBetween('-55 years', '-18 years'),
            'phone' => fake()->phoneNumber(),
            'address_line_1' => fake()->streetAddress(),
            'city' => fake()->city(),
            'state' => 'CO',
            'zip_code' => fake()->postcode(),
            'emergency_contact_name' => fake()->name(),
            'emergency_contact_phone' => fake()->phoneNumber(),
            'emergency_contact_relation' => fake()->randomElement(['Spouse', 'Parent', 'Sibling', 'Friend']),
        ];
    }

    /**
     * Employee on leave.
     */
    public function onLeave(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => 'on_leave',
        ]);
    }

    /**
     * Terminated employee.
     */
    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'employment_status' => 'terminated',
            'termination_date' => fake()->dateTimeBetween('-6 months', 'now'),
        ]);
    }

    /**
     * Salaried employee.
     */
    public function salaried(): static
    {
        return $this->state(fn (array $attributes) => [
            'pay_type' => 'salary',
            'pay_rate' => fake()->randomFloat(2, 40000, 85000),
        ]);
    }

    /**
     * Commission-only employee (no base pay rate).
     */
    public function commissionOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'pay_type' => 'commission',
            'pay_rate' => null,
            'commission_rules' => [
                ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.10, 'active' => true],
            ],
        ]);
    }

    /**
     * Hourly + commission employee.
     */
    public function hourlyPlusCommission(): static
    {
        return $this->state(fn (array $attributes) => [
            'pay_type' => 'hourly_plus_commission',
            'commission_rules' => [
                ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.05, 'active' => true],
            ],
        ]);
    }

    /**
     * Salary + commission employee.
     */
    public function salaryPlusCommission(): static
    {
        return $this->state(fn (array $attributes) => [
            'pay_type' => 'salary_plus_commission',
            'pay_rate' => fake()->randomFloat(2, 40000, 85000),
            'commission_rules' => [
                ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.05, 'active' => true],
            ],
        ]);
    }

    /**
     * Employee with completed onboarding.
     */
    public function onboarded(): static
    {
        return $this->state(fn (array $attributes) => [
            'onboarding_completed_at' => fake()->dateTimeBetween('-1 year', 'now'),
        ]);
    }

    /**
     * Employee with lead-safe certification.
     */
    public function leadSafeCertified(): static
    {
        return $this->state(fn (array $attributes) => [
            'lead_safe_cert_number' => 'LS-'.fake()->numerify('######'),
            'lead_safe_cert_expiration' => fake()->dateTimeBetween('+6 months', '+2 years'),
        ]);
    }

    /**
     * Employee with direct deposit info.
     */
    public function withDirectDeposit(): static
    {
        return $this->state(fn (array $attributes) => [
            'bank_name' => fake()->company().' Bank',
            'bank_routing_number' => fake()->numerify('#########'),
            'bank_account_number' => fake()->numerify('##########'),
            'bank_account_type' => fake()->randomElement(['checking', 'savings']),
        ]);
    }

    /**
     * Employee with W-4 data.
     */
    public function withW4(): static
    {
        return $this->state(fn (array $attributes) => [
            'w4_filing_status' => fake()->randomElement(['single', 'married', 'head_of_household']),
            'w4_allowances' => fake()->numberBetween(0, 5),
            'w4_additional_withholding' => fake()->randomFloat(2, 0, 100),
        ]);
    }

    /**
     * Employee with commission rules for a specific source.
     *
     * @param  array<int, array{source: string, type: string, rate?: float, amount?: float}>|null  $rules
     */
    public function commissioned(?array $rules = null): static
    {
        return $this->state(fn (array $attributes) => [
            'commission_rules' => $rules ?? [
                ['source' => 'estimate', 'type' => 'revenue_percentage', 'rate' => 0.05, 'active' => true],
            ],
        ]);
    }

    /**
     * Employee with lead commission.
     */
    public function withLeadCommission(string $type = 'flat', float $amount = 25): static
    {
        return $this->state(function (array $attributes) use ($type, $amount) {
            $rules = $attributes['commission_rules'] ?? [];
            $rules[] = ['source' => 'lead', 'type' => $type, 'amount' => $amount, 'active' => true];

            return ['commission_rules' => $rules];
        });
    }

    /**
     * Employee with estimate commission.
     */
    public function withEstimateCommission(string $type = 'revenue_percentage', float $rate = 0.05): static
    {
        return $this->state(function (array $attributes) use ($type, $rate) {
            $rules = $attributes['commission_rules'] ?? [];
            $rules[] = ['source' => 'estimate', 'type' => $type, 'rate' => $rate, 'active' => true];

            return ['commission_rules' => $rules];
        });
    }

    /**
     * Employee with job commission.
     */
    public function withJobCommission(string $type = 'revenue_percentage', float $rate = 0.03): static
    {
        return $this->state(function (array $attributes) use ($type, $rate) {
            $rules = $attributes['commission_rules'] ?? [];
            $rules[] = ['source' => 'job', 'type' => $type, 'rate' => $rate, 'active' => true];

            return ['commission_rules' => $rules];
        });
    }

    /**
     * Fully complete employee profile.
     */
    public function complete(): static
    {
        return $this->onboarded()
            ->leadSafeCertified()
            ->withDirectDeposit()
            ->withW4()
            ->state(fn (array $attributes) => [
                'ssn' => fake()->numerify('###-##-####'),
            ]);
    }
}

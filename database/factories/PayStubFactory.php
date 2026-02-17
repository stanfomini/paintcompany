<?php

namespace Database\Factories;

use App\Models\EmployeeProfile;
use App\Models\PayPeriod;
use App\Models\PayStub;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayStub>
 */
class PayStubFactory extends Factory
{
    protected $model = PayStub::class;

    public function definition(): array
    {
        $regularHours = fake()->randomFloat(2, 32, 40);
        $overtimeHours = fake()->randomFloat(2, 0, 8);
        $rate = fake()->randomFloat(2, 15, 45);
        $overtimeRate = round($rate * 1.5, 2);

        $gross = round(($regularHours * $rate) + ($overtimeHours * $overtimeRate), 2);
        $federalTax = round($gross * 0.12, 2);
        $stateTax = round($gross * 0.044, 2);
        $socialSecurity = round($gross * 0.062, 2);
        $medicare = round($gross * 0.0145, 2);
        $totalDeductions = round($federalTax + $stateTax + $socialSecurity + $medicare, 2);

        return [
            'employee_profile_id' => EmployeeProfile::factory(),
            'pay_period_id' => PayPeriod::factory(),
            'hours_regular' => $regularHours,
            'hours_overtime' => $overtimeHours,
            'hours_pto' => 0,
            'rate_regular' => $rate,
            'rate_overtime' => $overtimeRate,
            'gross_pay' => $gross,
            'federal_tax' => $federalTax,
            'state_tax' => $stateTax,
            'local_tax' => 0,
            'social_security' => $socialSecurity,
            'medicare' => $medicare,
            'additional_medicare' => 0,
            'total_deductions' => $totalDeductions,
            'net_pay' => round($gross - $totalDeductions, 2),
            'status' => 'draft',
        ];
    }

    /**
     * Finalized pay stub.
     */
    public function finalized(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'finalized',
            'check_number' => (string) fake()->numberBetween(1000, 9999),
        ]);
    }

    /**
     * Pay stub with PTO hours.
     */
    public function withPto(float $hours = 8): static
    {
        return $this->state(fn (array $attributes) => [
            'hours_pto' => $hours,
        ]);
    }

    /**
     * Pay stub with YTD data.
     */
    public function withYtd(): static
    {
        return $this->state(fn (array $attributes) => [
            'ytd_gross' => fake()->randomFloat(2, 10000, 80000),
            'ytd_federal_tax' => fake()->randomFloat(2, 1000, 10000),
            'ytd_state_tax' => fake()->randomFloat(2, 400, 4000),
            'ytd_social_security' => fake()->randomFloat(2, 600, 5000),
            'ytd_medicare' => fake()->randomFloat(2, 150, 1200),
        ]);
    }
}

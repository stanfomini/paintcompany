<?php

namespace App\Services;

class TaxCalculationService
{
    /**
     * 2025 Federal income tax brackets (annual taxable income).
     *
     * @var array<string, array<int, array{min: float, max: float, rate: float, base: float}>>
     */
    private const FEDERAL_BRACKETS = [
        'single' => [
            ['min' => 0, 'max' => 11925, 'rate' => 0.10, 'base' => 0],
            ['min' => 11925, 'max' => 48475, 'rate' => 0.12, 'base' => 1192.50],
            ['min' => 48475, 'max' => 103350, 'rate' => 0.22, 'base' => 5578.50],
            ['min' => 103350, 'max' => 197300, 'rate' => 0.24, 'base' => 17651.00],
            ['min' => 197300, 'max' => 250525, 'rate' => 0.32, 'base' => 40199.00],
            ['min' => 250525, 'max' => 626350, 'rate' => 0.35, 'base' => 57231.00],
            ['min' => 626350, 'max' => PHP_FLOAT_MAX, 'rate' => 0.37, 'base' => 188769.75],
        ],
        'married' => [
            ['min' => 0, 'max' => 23850, 'rate' => 0.10, 'base' => 0],
            ['min' => 23850, 'max' => 96950, 'rate' => 0.12, 'base' => 2385.00],
            ['min' => 96950, 'max' => 206700, 'rate' => 0.22, 'base' => 11157.00],
            ['min' => 206700, 'max' => 394600, 'rate' => 0.24, 'base' => 35302.00],
            ['min' => 394600, 'max' => 501050, 'rate' => 0.32, 'base' => 80398.00],
            ['min' => 501050, 'max' => 751600, 'rate' => 0.35, 'base' => 114462.00],
            ['min' => 751600, 'max' => PHP_FLOAT_MAX, 'rate' => 0.37, 'base' => 202154.50],
        ],
        'head_of_household' => [
            ['min' => 0, 'max' => 17000, 'rate' => 0.10, 'base' => 0],
            ['min' => 17000, 'max' => 64850, 'rate' => 0.12, 'base' => 1700.00],
            ['min' => 64850, 'max' => 103350, 'rate' => 0.22, 'base' => 7442.00],
            ['min' => 103350, 'max' => 197300, 'rate' => 0.24, 'base' => 15912.00],
            ['min' => 197300, 'max' => 250500, 'rate' => 0.32, 'base' => 38460.00],
            ['min' => 250500, 'max' => 626350, 'rate' => 0.35, 'base' => 55484.00],
            ['min' => 626350, 'max' => PHP_FLOAT_MAX, 'rate' => 0.37, 'base' => 187031.50],
        ],
    ];

    /**
     * 2025 Standard deductions by filing status.
     */
    private const STANDARD_DEDUCTIONS = [
        'single' => 14600,
        'married' => 29200,
        'head_of_household' => 21900,
    ];

    /**
     * Social Security wage base for 2025.
     */
    private const SS_WAGE_BASE = 168600;

    /**
     * Social Security tax rate.
     */
    private const SS_RATE = 0.062;

    /**
     * Medicare tax rate.
     */
    private const MEDICARE_RATE = 0.0145;

    /**
     * Additional Medicare tax rate (on wages over threshold).
     */
    private const ADDITIONAL_MEDICARE_RATE = 0.009;

    /**
     * Additional Medicare threshold.
     */
    private const ADDITIONAL_MEDICARE_THRESHOLD = 200000;

    /**
     * Colorado state income tax flat rate (2025).
     */
    private const COLORADO_STATE_RATE = 0.044;

    /**
     * Calculate federal income tax withholding per pay period.
     */
    public function calculateFederalTax(
        float $grossPerPeriod,
        string $filingStatus,
        int $allowances,
        float $additionalWithholding,
        int $payPeriodsPerYear
    ): float {
        // Annualize the gross pay
        $annualGross = $grossPerPeriod * $payPeriodsPerYear;

        // Subtract standard deduction
        $standardDeduction = self::STANDARD_DEDUCTIONS[$filingStatus] ?? self::STANDARD_DEDUCTIONS['single'];
        $allowanceDeduction = $allowances * 4300; // Per-allowance deduction (2025)
        $taxableIncome = max(0, $annualGross - $standardDeduction - $allowanceDeduction);

        // Apply progressive brackets
        $brackets = self::FEDERAL_BRACKETS[$filingStatus] ?? self::FEDERAL_BRACKETS['single'];
        $annualTax = $this->applyBrackets($taxableIncome, $brackets);

        // Per-period tax + additional withholding
        $perPeriodTax = ($annualTax / $payPeriodsPerYear) + $additionalWithholding;

        return round(max(0, $perPeriodTax), 2);
    }

    /**
     * Calculate Social Security tax for a pay period.
     */
    public function calculateSocialSecurity(float $grossPay, float $ytdGross): float
    {
        // If YTD already exceeds wage base, no more SS tax
        if ($ytdGross >= self::SS_WAGE_BASE) {
            return 0;
        }

        // If this period would push over the wage base, only tax the remaining
        $taxableAmount = $grossPay;
        if (($ytdGross + $grossPay) > self::SS_WAGE_BASE) {
            $taxableAmount = self::SS_WAGE_BASE - $ytdGross;
        }

        return round($taxableAmount * self::SS_RATE, 2);
    }

    /**
     * Calculate Medicare tax for a pay period.
     * Returns [regular_medicare, additional_medicare].
     *
     * @return array{medicare: float, additional_medicare: float}
     */
    public function calculateMedicare(float $grossPay, float $ytdGross): array
    {
        $regularMedicare = round($grossPay * self::MEDICARE_RATE, 2);

        // Additional Medicare on wages over $200,000
        $additionalMedicare = 0;
        $totalWithCurrent = $ytdGross + $grossPay;

        if ($totalWithCurrent > self::ADDITIONAL_MEDICARE_THRESHOLD) {
            $additionalWages = $grossPay;

            // If employee just crossed the threshold this period
            if ($ytdGross < self::ADDITIONAL_MEDICARE_THRESHOLD) {
                $additionalWages = $totalWithCurrent - self::ADDITIONAL_MEDICARE_THRESHOLD;
            }

            $additionalMedicare = round($additionalWages * self::ADDITIONAL_MEDICARE_RATE, 2);
        }

        return [
            'medicare' => $regularMedicare,
            'additional_medicare' => $additionalMedicare,
        ];
    }

    /**
     * Calculate Colorado state income tax (flat rate).
     */
    public function calculateColoradoStateTax(float $grossPay): float
    {
        return round($grossPay * self::COLORADO_STATE_RATE, 2);
    }

    /**
     * Calculate all taxes for a pay period.
     *
     * @return array{federal: float, state: float, social_security: float, medicare: float, additional_medicare: float}
     */
    public function calculateAllTaxes(
        float $grossPay,
        float $ytdGross,
        string $filingStatus,
        int $allowances,
        float $additionalWithholding,
        int $payPeriodsPerYear
    ): array {
        $federal = $this->calculateFederalTax(
            $grossPay,
            $filingStatus,
            $allowances,
            $additionalWithholding,
            $payPeriodsPerYear
        );

        $state = $this->calculateColoradoStateTax($grossPay);
        $socialSecurity = $this->calculateSocialSecurity($grossPay, $ytdGross);
        $medicareResult = $this->calculateMedicare($grossPay, $ytdGross);

        return [
            'federal' => $federal,
            'state' => $state,
            'social_security' => $socialSecurity,
            'medicare' => $medicareResult['medicare'],
            'additional_medicare' => $medicareResult['additional_medicare'],
        ];
    }

    /**
     * Get the number of pay periods per year for a given type.
     */
    public function getPayPeriodsPerYear(string $type): int
    {
        return match ($type) {
            'weekly' => 52,
            'biweekly' => 26,
            'semimonthly' => 24,
            'monthly' => 12,
            default => 26,
        };
    }

    /**
     * Apply progressive tax brackets to taxable income.
     *
     * @param  array<int, array{min: float, max: float, rate: float, base: float}>  $brackets
     */
    private function applyBrackets(float $taxableIncome, array $brackets): float
    {
        foreach ($brackets as $bracket) {
            if ($taxableIncome <= $bracket['max']) {
                return $bracket['base'] + (($taxableIncome - $bracket['min']) * $bracket['rate']);
            }
        }

        // Should not reach here, but return using highest bracket
        $last = end($brackets);

        return $last['base'] + (($taxableIncome - $last['min']) * $last['rate']);
    }
}

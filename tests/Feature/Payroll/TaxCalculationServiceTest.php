<?php

use App\Services\TaxCalculationService;

beforeEach(function () {
    $this->service = new TaxCalculationService;
});

it('calculates federal tax for single filer', function () {
    // $2,000 gross biweekly = $52,000 annual
    // Standard deduction: $14,600 -> taxable: $37,400
    // Tax: $1,192.50 + ($37,400 - $11,925) * 0.12 = $1,192.50 + $3,057 = $4,249.50
    // Per period: $4,249.50 / 26 = $163.44
    $tax = $this->service->calculateFederalTax(2000, 'single', 0, 0, 26);

    expect($tax)->toBeGreaterThan(100)
        ->toBeLessThan(250);
});

it('calculates federal tax for married filer with lower rate', function () {
    // Married filers have larger standard deduction, so lower tax
    $singleTax = $this->service->calculateFederalTax(2000, 'single', 0, 0, 26);
    $marriedTax = $this->service->calculateFederalTax(2000, 'married', 0, 0, 26);

    expect($marriedTax)->toBeLessThan($singleTax);
});

it('adds additional withholding to federal tax', function () {
    $baseTax = $this->service->calculateFederalTax(2000, 'single', 0, 0, 26);
    $withExtra = $this->service->calculateFederalTax(2000, 'single', 0, 50, 26);

    expect($withExtra)->toBe(round($baseTax + 50, 2));
});

it('reduces federal tax with allowances', function () {
    $noAllowances = $this->service->calculateFederalTax(2000, 'single', 0, 0, 26);
    $withAllowances = $this->service->calculateFederalTax(2000, 'single', 2, 0, 26);

    expect($withAllowances)->toBeLessThan($noAllowances);
});

it('never returns negative federal tax', function () {
    // Very low income
    $tax = $this->service->calculateFederalTax(100, 'single', 5, 0, 26);

    expect($tax)->toBeGreaterThanOrEqual(0);
});

it('calculates social security at 6.2%', function () {
    $ss = $this->service->calculateSocialSecurity(2000, 0);

    expect($ss)->toBe(124.00); // 2000 * 0.062
});

it('caps social security at wage base', function () {
    // YTD already at $167,600, gross of $2,000 would push over $168,600 cap
    $ss = $this->service->calculateSocialSecurity(2000, 167600);

    // Only $1,000 remaining under wage base
    expect($ss)->toBe(62.00); // 1000 * 0.062
});

it('stops social security when over wage base', function () {
    $ss = $this->service->calculateSocialSecurity(2000, 170000);

    expect($ss)->toBe(0.00);
});

it('calculates medicare at 1.45%', function () {
    $result = $this->service->calculateMedicare(2000, 0);

    expect($result['medicare'])->toBe(29.00) // 2000 * 0.0145
        ->and($result['additional_medicare'])->toEqual(0);
});

it('adds additional medicare over 200k threshold', function () {
    $result = $this->service->calculateMedicare(2000, 199000);

    expect($result['medicare'])->toBe(29.00)
        ->and($result['additional_medicare'])->toBe(round(1000 * 0.009, 2)); // Only $1k over threshold
});

it('calculates colorado state tax at 4.4%', function () {
    $tax = $this->service->calculateColoradoStateTax(2000);

    expect($tax)->toBe(88.00); // 2000 * 0.044
});

it('calculates all taxes together', function () {
    $result = $this->service->calculateAllTaxes(2000, 0, 'single', 0, 0, 26);

    expect($result)
        ->toHaveKeys(['federal', 'state', 'social_security', 'medicare', 'additional_medicare'])
        ->and($result['federal'])->toBeGreaterThan(0)
        ->and($result['state'])->toBe(88.00)
        ->and($result['social_security'])->toBe(124.00)
        ->and($result['medicare'])->toBe(29.00)
        ->and($result['additional_medicare'])->toEqual(0);
});

it('returns correct pay periods per year', function () {
    expect($this->service->getPayPeriodsPerYear('weekly'))->toBe(52)
        ->and($this->service->getPayPeriodsPerYear('biweekly'))->toBe(26)
        ->and($this->service->getPayPeriodsPerYear('semimonthly'))->toBe(24)
        ->and($this->service->getPayPeriodsPerYear('monthly'))->toBe(12);
});

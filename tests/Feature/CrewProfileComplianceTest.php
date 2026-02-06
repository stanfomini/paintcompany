<?php

use App\Models\CrewProfile;

test('isInsuranceExpiringSoon returns false when no expiration date set', function () {
    $profile = new CrewProfile(['insurance_expiration_date' => null]);

    expect($profile->isInsuranceExpiringSoon())->toBeFalse();
});

test('isInsuranceExpiringSoon returns true when expiring within 30 days', function () {
    $profile = new CrewProfile(['insurance_expiration_date' => now()->addDays(15)]);

    expect($profile->isInsuranceExpiringSoon())->toBeTrue();
});

test('isInsuranceExpiringSoon returns false when more than 30 days away', function () {
    $profile = new CrewProfile(['insurance_expiration_date' => now()->addDays(45)]);

    expect($profile->isInsuranceExpiringSoon())->toBeFalse();
});

test('isInsuranceExpiringSoon returns false when already expired', function () {
    $profile = new CrewProfile(['insurance_expiration_date' => now()->subDays(5)]);

    expect($profile->isInsuranceExpiringSoon())->toBeFalse();
});

test('isInsuranceExpired returns false when no expiration date set', function () {
    $profile = new CrewProfile(['insurance_expiration_date' => null]);

    expect($profile->isInsuranceExpired())->toBeFalse();
});

test('isInsuranceExpired returns true when date is in the past', function () {
    $profile = new CrewProfile(['insurance_expiration_date' => now()->subDays(5)]);

    expect($profile->isInsuranceExpired())->toBeTrue();
});

test('isInsuranceExpired returns false when date is in the future', function () {
    $profile = new CrewProfile(['insurance_expiration_date' => now()->addDays(30)]);

    expect($profile->isInsuranceExpired())->toBeFalse();
});

test('isLicenseExpiringSoon returns false when no expiration date set', function () {
    $profile = new CrewProfile(['license_expiration_date' => null]);

    expect($profile->isLicenseExpiringSoon())->toBeFalse();
});

test('isLicenseExpiringSoon returns true when expiring within 30 days', function () {
    $profile = new CrewProfile(['license_expiration_date' => now()->addDays(20)]);

    expect($profile->isLicenseExpiringSoon())->toBeTrue();
});

test('isLicenseExpiringSoon returns false when more than 30 days away', function () {
    $profile = new CrewProfile(['license_expiration_date' => now()->addDays(60)]);

    expect($profile->isLicenseExpiringSoon())->toBeFalse();
});

test('isLicenseExpired returns false when no expiration date set', function () {
    $profile = new CrewProfile(['license_expiration_date' => null]);

    expect($profile->isLicenseExpired())->toBeFalse();
});

test('isLicenseExpired returns true when date is in the past', function () {
    $profile = new CrewProfile(['license_expiration_date' => now()->subDays(10)]);

    expect($profile->isLicenseExpired())->toBeTrue();
});

test('hasComplianceWarnings returns false when nothing is expiring or expired', function () {
    $profile = new CrewProfile([
        'insurance_expiration_date' => now()->addMonths(6),
        'license_expiration_date' => now()->addYear(),
    ]);

    expect($profile->hasComplianceWarnings())->toBeFalse();
});

test('hasComplianceWarnings returns true when insurance is expiring soon', function () {
    $profile = new CrewProfile([
        'insurance_expiration_date' => now()->addDays(15),
        'license_expiration_date' => now()->addYear(),
    ]);

    expect($profile->hasComplianceWarnings())->toBeTrue();
});

test('hasComplianceWarnings returns true when license is expired', function () {
    $profile = new CrewProfile([
        'insurance_expiration_date' => now()->addMonths(6),
        'license_expiration_date' => now()->subDays(10),
    ]);

    expect($profile->hasComplianceWarnings())->toBeTrue();
});

test('getComplianceStatus returns compliant when no issues', function () {
    $profile = new CrewProfile([
        'insurance_expiration_date' => now()->addMonths(6),
        'license_expiration_date' => now()->addYear(),
    ]);

    expect($profile->getComplianceStatus())->toBe('compliant');
});

test('getComplianceStatus returns warning when expiring soon', function () {
    $profile = new CrewProfile([
        'insurance_expiration_date' => now()->addDays(20),
        'license_expiration_date' => now()->addYear(),
    ]);

    expect($profile->getComplianceStatus())->toBe('warning');
});

test('getComplianceStatus returns expired when any document expired', function () {
    $profile = new CrewProfile([
        'insurance_expiration_date' => now()->subDays(5),
        'license_expiration_date' => now()->addYear(),
    ]);

    expect($profile->getComplianceStatus())->toBe('expired');
});

test('getComplianceStatus returns compliant when no dates set', function () {
    $profile = new CrewProfile([
        'insurance_expiration_date' => null,
        'license_expiration_date' => null,
    ]);

    expect($profile->getComplianceStatus())->toBe('compliant');
});

// Workers' Comp tests

test('isWorkersCompExpiringSoon returns false when no expiration date set', function () {
    $profile = new CrewProfile(['workers_comp_expiration_date' => null]);

    expect($profile->isWorkersCompExpiringSoon())->toBeFalse();
});

test('isWorkersCompExpiringSoon returns true when expiring within 30 days', function () {
    $profile = new CrewProfile(['workers_comp_expiration_date' => now()->addDays(15)]);

    expect($profile->isWorkersCompExpiringSoon())->toBeTrue();
});

test('isWorkersCompExpiringSoon returns false when more than 30 days away', function () {
    $profile = new CrewProfile(['workers_comp_expiration_date' => now()->addDays(45)]);

    expect($profile->isWorkersCompExpiringSoon())->toBeFalse();
});

test('isWorkersCompExpiringSoon returns false when already expired', function () {
    $profile = new CrewProfile(['workers_comp_expiration_date' => now()->subDays(5)]);

    expect($profile->isWorkersCompExpiringSoon())->toBeFalse();
});

test('isWorkersCompExpired returns false when no expiration date set', function () {
    $profile = new CrewProfile(['workers_comp_expiration_date' => null]);

    expect($profile->isWorkersCompExpired())->toBeFalse();
});

test('isWorkersCompExpired returns true when date is in the past', function () {
    $profile = new CrewProfile(['workers_comp_expiration_date' => now()->subDays(5)]);

    expect($profile->isWorkersCompExpired())->toBeTrue();
});

test('isWorkersCompExpired returns false when date is in the future', function () {
    $profile = new CrewProfile(['workers_comp_expiration_date' => now()->addDays(30)]);

    expect($profile->isWorkersCompExpired())->toBeFalse();
});

test('getComplianceStatus returns expired when workers comp is expired', function () {
    $profile = new CrewProfile([
        'insurance_expiration_date' => now()->addMonths(6),
        'license_expiration_date' => now()->addYear(),
        'workers_comp_expiration_date' => now()->subDays(5),
    ]);

    expect($profile->getComplianceStatus())->toBe('expired');
});

test('getComplianceStatus returns warning when workers comp is expiring soon', function () {
    $profile = new CrewProfile([
        'insurance_expiration_date' => now()->addMonths(6),
        'license_expiration_date' => now()->addYear(),
        'workers_comp_expiration_date' => now()->addDays(20),
    ]);

    expect($profile->getComplianceStatus())->toBe('warning');
});

test('hasComplianceWarnings returns true when workers comp is expiring soon', function () {
    $profile = new CrewProfile([
        'insurance_expiration_date' => now()->addMonths(6),
        'license_expiration_date' => now()->addYear(),
        'workers_comp_expiration_date' => now()->addDays(15),
    ]);

    expect($profile->hasComplianceWarnings())->toBeTrue();
});

test('hasComplianceWarnings returns true when workers comp is expired', function () {
    $profile = new CrewProfile([
        'insurance_expiration_date' => now()->addMonths(6),
        'license_expiration_date' => now()->addYear(),
        'workers_comp_expiration_date' => now()->subDays(10),
    ]);

    expect($profile->hasComplianceWarnings())->toBeTrue();
});

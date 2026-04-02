<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;

it('auto-populates timezone from area code on creation', function () {
    $company = Company::factory()->create();
    $contact = Contact::create([
        'company_id' => $company->id,
        'phone' => '+13035551234',
        'first_name' => 'Test',
    ]);

    expect($contact->timezone)->toBe('America/Denver');
});

it('does not overwrite existing timezone', function () {
    $company = Company::factory()->create();
    $contact = Contact::create([
        'company_id' => $company->id,
        'phone' => '+13035551234',
        'first_name' => 'Test',
        'timezone' => 'America/New_York',
    ]);

    expect($contact->timezone)->toBe('America/New_York');
});

it('handles unknown area codes gracefully', function () {
    $company = Company::factory()->create();
    $contact = Contact::create([
        'company_id' => $company->id,
        'phone' => '+19995551234',
        'first_name' => 'Test',
    ]);

    expect($contact->timezone)->toBeNull();
});

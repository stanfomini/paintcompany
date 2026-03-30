<?php

use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\NumberSelectionService;

beforeEach(function () {
    $this->service = new NumberSelectionService;
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
});

it('uses campaign-specific phone number when assigned', function () {
    $number = PhoneNumber::factory()->forAccount($this->account)->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);

    $campaign = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'phone_number_id' => $number->id,
    ]);

    $contact = Contact::factory()->forCompany($this->company)->create();

    $result = $this->service->selectNumber($this->company, $contact, $campaign);

    expect($result->id)->toBe($number->id);
});

it('uses local presence number matching contact area code', function () {
    $localNumber = PhoneNumber::factory()->forAccount($this->account)->create([
        'company_id' => $this->company->id,
        'is_active' => true,
        'area_code' => '303',
        'role' => 'shadow',
    ]);

    $campaign = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'phone_number_id' => null,
        'use_local_presence' => true,
    ]);

    $contact = Contact::factory()->forCompany($this->company)->withPhone('+13035551234')->create();

    $result = $this->service->selectNumber($this->company, $contact, $campaign);

    expect($result->id)->toBe($localNumber->id);
});

it('falls back to company main number', function () {
    $mainNumber = PhoneNumber::factory()->forAccount($this->account)->main()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);

    $this->company->update(['main_phone_number_id' => $mainNumber->id]);

    $contact = Contact::factory()->forCompany($this->company)->create();

    $result = $this->service->selectNumber($this->company, $contact);

    expect($result->id)->toBe($mainNumber->id);
});

it('throws when no phone number is available', function () {
    $contact = Contact::factory()->forCompany($this->company)->create();

    $this->service->selectNumber($this->company, $contact);
})->throws(RuntimeException::class, 'No active outbound phone number available');

<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\TwilioAccount;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
});

it('loads the prospector settings page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.settings'))
        ->assertOk();
});

it('loads the prospector companies page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.companies'))
        ->assertOk();
});

it('loads the prospector company detail page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.companies.show', $this->company->id))
        ->assertOk();
});

it('loads the prospector dashboard page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.dashboard'))
        ->assertOk();
});

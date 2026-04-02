<?php

use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;

it('resets calls_today to zero for all campaign contacts', function () {
    $company = Company::factory()->create();
    $campaign = Campaign::factory()->create(['company_id' => $company->id]);
    $contact = Contact::factory()->create(['company_id' => $company->id]);

    CampaignContact::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'calls_today' => 3,
    ]);

    $this->artisan('prospector:reset-daily-calls')
        ->assertExitCode(0);

    expect(CampaignContact::first()->calls_today)->toBe(0);
});

it('does not update contacts with zero calls', function () {
    $company = Company::factory()->create();
    $campaign = Campaign::factory()->create(['company_id' => $company->id]);
    $contact = Contact::factory()->create(['company_id' => $company->id]);

    CampaignContact::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'calls_today' => 0,
    ]);

    $this->artisan('prospector:reset-daily-calls')
        ->expectsOutputToContain('Reset calls_today for 0')
        ->assertExitCode(0);
});

<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Services\Prospector\PowerDialerService;

// ─── dialForAgent Method Exists ─────────────────────────────

it('has a dialForAgent method that works without a session', function () {
    $service = app(PowerDialerService::class);
    expect(method_exists($service, 'dialForAgent'))->toBeTrue();
});

// ─── Returns Null When No Contacts Queued ───────────────────

it('returns null when no contacts are queued', function () {
    $company = Company::factory()->create();
    $agent = Agent::factory()->forCompany($company)->available()->create();
    $agent->companies()->syncWithoutDetaching([$company->id]);

    $service = app(PowerDialerService::class);
    $result = $service->dialForAgent($agent);

    expect($result)->toBeNull();
});

// ─── Returns Null With Campaign Filter and Empty Queue ──────

it('returns null when campaign has no queued contacts', function () {
    $company = Company::factory()->create();
    $agent = Agent::factory()->forCompany($company)->available()->create();
    $agent->companies()->syncWithoutDetaching([$company->id]);
    $campaign = Campaign::factory()->forCompany($company)->active()->create();

    $service = app(PowerDialerService::class);
    $result = $service->dialForAgent($agent, $campaign->id);

    expect($result)->toBeNull();
});

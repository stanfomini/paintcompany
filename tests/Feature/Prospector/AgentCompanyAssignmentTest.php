<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Company;
use App\Models\User;

it('can assign an agent to multiple companies', function () {
    $user = User::factory()->internal()->create();
    $companyA = Company::factory()->create(['name' => 'Company A']);
    $companyB = Company::factory()->create(['name' => 'Company B']);
    $agent = Agent::factory()->create(['user_id' => $user->id, 'company_id' => $companyA->id]);

    $agent->companies()->attach([$companyA->id, $companyB->id]);

    expect($agent->companies)->toHaveCount(2);
    expect($agent->companies->pluck('name')->sort()->values()->all())
        ->toBe(['Company A', 'Company B']);
});

it('can list agents for a company', function () {
    $company = Company::factory()->create();
    $agentA = Agent::factory()->create(['company_id' => $company->id]);
    $agentB = Agent::factory()->create(['company_id' => $company->id]);

    $company->agents()->attach([$agentA->id, $agentB->id]);

    expect($company->agents)->toHaveCount(2);
});

it('returns all company IDs for an agent', function () {
    $user = User::factory()->internal()->create();
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();
    $companyC = Company::factory()->create();
    $agent = Agent::factory()->create(['user_id' => $user->id, 'company_id' => $companyA->id]);

    $agent->companies()->attach([$companyA->id, $companyB->id, $companyC->id]);

    expect($agent->getCompanyIds())->toHaveCount(3);
});

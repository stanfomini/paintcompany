<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\Pipeline;
use App\Models\Prospector\PipelineStage;
use App\Services\Prospector\SmartQueueService;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->agent = Agent::factory()->create(['company_id' => $this->company->id]);
    $this->agent->companies()->attach($this->company->id);
    $this->pipeline = Pipeline::factory()->create(['is_default' => true]);
    $this->stage = PipelineStage::factory()->create(['pipeline_id' => $this->pipeline->id, 'sort_order' => 1]);
    $this->service = app(SmartQueueService::class);
});

it('returns contacts that have a pipeline stage', function () {
    $inQueue = Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
    ]);
    // No pipeline stage — should NOT appear
    Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => null,
    ]);

    $queue = $this->service->getQueue($this->agent);

    expect($queue)->toHaveCount(1);
    expect($queue->first()->id)->toBe($inQueue->id);
});

it('returns contacts with unread SMS before regular contacts', function () {
    $regular = Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
        'has_unread_sms' => false,
    ]);
    $unreadSms = Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
        'has_unread_sms' => true,
    ]);

    $queue = $this->service->getQueue($this->agent);

    expect($queue)->toHaveCount(2);
    expect($queue->first()->id)->toBe($unreadSms->id);
});

it('returns contacts with active inbound call first', function () {
    $regular = Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
    ]);
    $inboundCall = Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
    ]);
    Call::factory()->create([
        'company_id' => $this->company->id,
        'contact_id' => $inboundCall->id,
        'direction' => 'inbound',
        'status' => 'ringing',
    ]);

    $queue = $this->service->getQueue($this->agent);

    expect($queue->first()->id)->toBe($inboundCall->id);
});

it('returns contacts with callback due elevated', function () {
    $regular = Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
        'follow_up_at' => null,
    ]);
    $callbackDue = Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
        'follow_up_at' => now()->subHour(),
    ]);

    $queue = $this->service->getQueue($this->agent);

    expect($queue->first()->id)->toBe($callbackDue->id);
});

it('excludes DNC contacts', function () {
    Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
        'do_not_call' => true,
    ]);

    $queue = $this->service->getQueue($this->agent);

    expect($queue)->toHaveCount(0);
});

it('excludes contacts from unassigned companies', function () {
    $otherCompany = Company::factory()->create();
    Contact::factory()->create([
        'company_id' => $otherCompany->id,
        'pipeline_stage_id' => $this->stage->id,
    ]);

    $queue = $this->service->getQueue($this->agent);

    expect($queue)->toHaveCount(0);
});

it('returns queue items across multiple companies', function () {
    $companyB = Company::factory()->create();
    $this->agent->companies()->attach($companyB->id);

    Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
    ]);
    Contact::factory()->create([
        'company_id' => $companyB->id,
        'pipeline_stage_id' => $this->stage->id,
    ]);

    $queue = $this->service->getQueue($this->agent);

    expect($queue)->toHaveCount(2);
});

it('excludes contacts claimed by other agents', function () {
    $otherAgent = Agent::factory()->create(['company_id' => $this->company->id]);
    Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
        'claimed_by_agent_id' => $otherAgent->id,
    ]);

    $queue = $this->service->getQueue($this->agent);

    expect($queue)->toHaveCount(0);
});

it('claims a contact atomically', function () {
    $contact = Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
    ]);

    $claimed = $this->service->claim($contact, $this->agent);

    expect($claimed)->toBeTrue();
    expect($contact->fresh()->claimed_by_agent_id)->toBe($this->agent->id);
});

it('fails to claim an already claimed contact', function () {
    $otherAgent = Agent::factory()->create(['company_id' => $this->company->id]);
    $contact = Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
        'claimed_by_agent_id' => $otherAgent->id,
    ]);

    $claimed = $this->service->claim($contact, $this->agent);

    expect($claimed)->toBeFalse();
});

it('filters queue by company', function () {
    $companyB = Company::factory()->create();
    $this->agent->companies()->attach($companyB->id);

    Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
    ]);
    $contactB = Contact::factory()->create([
        'company_id' => $companyB->id,
        'pipeline_stage_id' => $this->stage->id,
    ]);

    $queue = $this->service->getQueue($this->agent, companyId: $companyB->id);

    expect($queue)->toHaveCount(1);
    expect($queue->first()->id)->toBe($contactB->id);
});

it('filters queue by pipeline stage', function () {
    $stageB = PipelineStage::factory()->create(['pipeline_id' => $this->pipeline->id, 'sort_order' => 2]);

    Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
    ]);
    $contactB = Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $stageB->id,
    ]);

    $queue = $this->service->getQueue($this->agent, pipelineStageId: $stageB->id);

    expect($queue)->toHaveCount(1);
    expect($queue->first()->id)->toBe($contactB->id);
});

it('returns the next contact as the first unclaimed item', function () {
    $contact = Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $this->stage->id,
    ]);

    $next = $this->service->getNext($this->agent);

    expect($next)->not->toBeNull();
    expect($next->id)->toBe($contact->id);
});

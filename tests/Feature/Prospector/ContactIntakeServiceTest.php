<?php

use App\Events\Prospector\NewLeadArrived;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\Pipeline;
use App\Models\Prospector\PipelineStage;
use App\Models\Prospector\SmsMessage;
use App\Services\Prospector\ContactIntakeService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->pipeline = Pipeline::factory()->create(['is_default' => true]);
    $this->stage1 = PipelineStage::factory()->create([
        'pipeline_id' => $this->pipeline->id,
        'sort_order' => 1,
        'name' => 'New Lead',
    ]);
    PipelineStage::factory()->create([
        'pipeline_id' => $this->pipeline->id,
        'sort_order' => 2,
        'name' => 'Contacted',
    ]);
    $this->service = app(ContactIntakeService::class);
});

it('creates a new contact with default pipeline stage', function () {
    $result = $this->service->findOrCreate($this->company, '+13035551234', [
        'first_name' => 'Jane',
        'source' => 'webhook',
    ]);

    expect($result->wasCreated)->toBeTrue();
    expect($result->contact->pipeline_stage_id)->toBe($this->stage1->id);
    expect($result->contact->first_name)->toBe('Jane');
    expect($result->contact->source)->toBe('webhook');
});

it('returns existing contact when phone matches', function () {
    $existing = Contact::factory()->create([
        'company_id' => $this->company->id,
        'phone' => '+13035551234',
        'first_name' => 'Existing',
    ]);

    $result = $this->service->findOrCreate($this->company, '+13035551234', [
        'first_name' => 'New Name',
    ]);

    expect($result->wasCreated)->toBeFalse();
    expect($result->contact->id)->toBe($existing->id);
    expect($result->contact->first_name)->toBe('Existing');
});

it('returns existing contact when phone_2 matches', function () {
    $existing = Contact::factory()->create([
        'company_id' => $this->company->id,
        'phone' => '+13035559999',
        'phone_2' => '+13035551234',
    ]);

    $result = $this->service->findOrCreate($this->company, '+13035551234');

    expect($result->wasCreated)->toBeFalse();
    expect($result->contact->id)->toBe($existing->id);
});

it('broadcasts NewLeadArrived on creation', function () {
    Event::fake([NewLeadArrived::class]);

    $this->service->findOrCreate($this->company, '+13035551234', [
        'first_name' => 'Test',
    ]);

    Event::assertDispatched(NewLeadArrived::class);
});

it('does not broadcast when contact already exists', function () {
    Contact::factory()->create([
        'company_id' => $this->company->id,
        'phone' => '+13035551234',
    ]);

    Event::fake([NewLeadArrived::class]);

    $this->service->findOrCreate($this->company, '+13035551234');

    Event::assertNotDispatched(NewLeadArrived::class);
});

it('backfills orphaned SMS messages on contact creation', function () {
    SmsMessage::factory()->create([
        'company_id' => $this->company->id,
        'contact_id' => null,
        'from_number' => '+13035551234',
        'direction' => 'inbound',
    ]);

    $result = $this->service->findOrCreate($this->company, '+13035551234', [
        'first_name' => 'Jane',
    ]);

    expect($result->contact->total_sms)->toBe(1);
    expect($result->contact->has_unread_sms)->toBeTrue();
    expect(SmsMessage::where('contact_id', $result->contact->id)->count())->toBe(1);
});

it('normalizes phone number before lookup', function () {
    $existing = Contact::factory()->create([
        'company_id' => $this->company->id,
        'phone' => '+13035551234',
    ]);

    $result = $this->service->findOrCreate($this->company, '(303) 555-1234');

    expect($result->wasCreated)->toBeFalse();
    expect($result->contact->id)->toBe($existing->id);
});

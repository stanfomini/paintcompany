<?php

use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\Sequence;
use App\Models\Prospector\SequenceEnrollment;
use App\Models\Prospector\SequenceStep;
use App\Services\Prospector\SequenceAutoEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $this->sequence = Sequence::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);
    SequenceStep::factory()->create([
        'sequence_id' => $this->sequence->id,
        'sort_order' => 0,
        'type' => 'sms',
        'config' => ['body' => 'Hello {first_name}'],
    ]);
    $this->service = app(SequenceAutoEnrollmentService::class);
});

it('enrolls contact in sequence from company default disposition mapping', function () {
    $this->company->update([
        'default_disposition_sequences' => ['no_answer' => $this->sequence->id],
    ]);

    $this->service->handleDisposition($this->contact, 'no_answer');

    $enrollment = SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $this->sequence->id)
        ->first();

    expect($enrollment)->not->toBeNull()
        ->and($enrollment->status)->toBe('active')
        ->and($enrollment->enrolled_via)->toBe('disposition');
});

it('uses campaign override over company default', function () {
    $overrideSequence = Sequence::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);
    SequenceStep::factory()->create([
        'sequence_id' => $overrideSequence->id,
        'sort_order' => 0,
        'type' => 'sms',
    ]);

    $this->company->update([
        'default_disposition_sequences' => ['no_answer' => $this->sequence->id],
    ]);

    $campaign = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'disposition_routes' => [
            'no_answer' => ['action' => 'stay', 'target_sequence_id' => $overrideSequence->id],
        ],
    ]);

    $this->service->handleDisposition($this->contact, 'no_answer', $campaign);

    expect(SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $overrideSequence->id)->exists())->toBeTrue()
        ->and(SequenceEnrollment::where('contact_id', $this->contact->id)
            ->where('sequence_id', $this->sequence->id)->exists())->toBeFalse();
});

it('exits previous disposition enrollment before enrolling in new sequence', function () {
    $newSequence = Sequence::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);
    SequenceStep::factory()->create([
        'sequence_id' => $newSequence->id,
        'sort_order' => 0,
        'type' => 'sms',
    ]);

    SequenceEnrollment::create([
        'sequence_id' => $this->sequence->id,
        'contact_id' => $this->contact->id,
        'status' => 'active',
        'enrolled_via' => 'disposition',
        'enrolled_at' => now(),
        'next_execution_at' => now(),
    ]);

    $this->company->update([
        'default_disposition_sequences' => ['voicemail' => $newSequence->id],
    ]);

    $this->service->handleDisposition($this->contact, 'voicemail');

    $old = SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $this->sequence->id)->first();
    $new = SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $newSequence->id)->first();

    expect($old->status)->toBe('exited')
        ->and($old->exit_reason)->toBe('disposition_change')
        ->and($new->status)->toBe('active')
        ->and($new->enrolled_via)->toBe('disposition');
});

it('does not exit manual enrollments on disposition change', function () {
    SequenceEnrollment::create([
        'sequence_id' => $this->sequence->id,
        'contact_id' => $this->contact->id,
        'status' => 'active',
        'enrolled_via' => 'manual',
        'enrolled_at' => now(),
        'next_execution_at' => now(),
    ]);

    $newSequence = Sequence::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);
    SequenceStep::factory()->create([
        'sequence_id' => $newSequence->id,
        'sort_order' => 0,
        'type' => 'sms',
    ]);

    $this->company->update([
        'default_disposition_sequences' => ['voicemail' => $newSequence->id],
    ]);

    $this->service->handleDisposition($this->contact, 'voicemail');

    $manual = SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $this->sequence->id)->first();

    expect($manual->status)->toBe('active');
});

it('does nothing when no sequence mapped for disposition', function () {
    $this->service->handleDisposition($this->contact, 'no_answer');

    expect(SequenceEnrollment::where('contact_id', $this->contact->id)->count())->toBe(0);
});

it('skips enrollment when sequence is not active', function () {
    $this->sequence->update(['status' => 'draft']);
    $this->company->update([
        'default_disposition_sequences' => ['no_answer' => $this->sequence->id],
    ]);

    $this->service->handleDisposition($this->contact, 'no_answer');

    expect(SequenceEnrollment::where('contact_id', $this->contact->id)->count())->toBe(0);
});

it('exits all active enrollments on DNC', function () {
    $sequence2 = Sequence::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);

    SequenceEnrollment::create([
        'sequence_id' => $this->sequence->id,
        'contact_id' => $this->contact->id,
        'status' => 'active',
        'enrolled_via' => 'disposition',
        'enrolled_at' => now(),
        'next_execution_at' => now(),
    ]);
    SequenceEnrollment::create([
        'sequence_id' => $sequence2->id,
        'contact_id' => $this->contact->id,
        'status' => 'active',
        'enrolled_via' => 'manual',
        'enrolled_at' => now(),
        'next_execution_at' => now(),
    ]);

    $this->service->handleDnc($this->contact);

    expect(SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('status', 'active')->count())->toBe(0)
        ->and(SequenceEnrollment::where('contact_id', $this->contact->id)
            ->where('status', 'exited')
            ->where('exit_reason', 'do_not_call')->count())->toBe(2);
});

it('disposition with dnc calls handleDnc', function () {
    SequenceEnrollment::create([
        'sequence_id' => $this->sequence->id,
        'contact_id' => $this->contact->id,
        'status' => 'active',
        'enrolled_via' => 'disposition',
        'enrolled_at' => now(),
        'next_execution_at' => now(),
    ]);

    $this->service->handleDisposition($this->contact, 'dnc');

    expect(SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('status', 'exited')
        ->where('exit_reason', 'do_not_call')->count())->toBe(1);
});

it('enrolls new contact in matching new_contact sequences', function () {
    $this->sequence->update([
        'trigger_type' => 'new_contact',
        'trigger_config' => null,
    ]);

    $this->service->handleNewContact($this->contact);

    $enrollment = SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $this->sequence->id)->first();

    expect($enrollment)->not->toBeNull()
        ->and($enrollment->enrolled_via)->toBe('new_contact');
});

it('filters new_contact by source when trigger_config.sources set', function () {
    $this->sequence->update([
        'trigger_type' => 'new_contact',
        'trigger_config' => ['sources' => ['webhook']],
    ]);

    $this->contact->update(['source' => 'manual']);
    $this->service->handleNewContact($this->contact);
    expect(SequenceEnrollment::where('contact_id', $this->contact->id)->count())->toBe(0);

    $this->contact->update(['source' => 'webhook']);
    $this->service->handleNewContact($this->contact);
    expect(SequenceEnrollment::where('contact_id', $this->contact->id)->count())->toBe(1);
});

it('enrolls contact when matching tag added', function () {
    $this->sequence->update([
        'trigger_type' => 'tag_added',
        'trigger_config' => ['tags' => ['hot-lead', 'interested']],
    ]);

    $this->service->handleTagAdded($this->contact, ['hot-lead']);

    $enrollment = SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $this->sequence->id)->first();

    expect($enrollment)->not->toBeNull()
        ->and($enrollment->enrolled_via)->toBe('tag_added');
});

it('does not enroll when tag does not match trigger config', function () {
    $this->sequence->update([
        'trigger_type' => 'tag_added',
        'trigger_config' => ['tags' => ['hot-lead']],
    ]);

    $this->service->handleTagAdded($this->contact, ['cold-lead']);

    expect(SequenceEnrollment::where('contact_id', $this->contact->id)->count())->toBe(0);
});

it('skips tag enrollment if already enrolled in sequence', function () {
    $this->sequence->update([
        'trigger_type' => 'tag_added',
        'trigger_config' => ['tags' => ['hot-lead']],
    ]);

    SequenceEnrollment::create([
        'sequence_id' => $this->sequence->id,
        'contact_id' => $this->contact->id,
        'status' => 'active',
        'enrolled_via' => 'tag_added',
        'enrolled_at' => now(),
        'next_execution_at' => now(),
    ]);

    $this->service->handleTagAdded($this->contact, ['hot-lead']);

    expect(SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $this->sequence->id)->count())->toBe(1);
});

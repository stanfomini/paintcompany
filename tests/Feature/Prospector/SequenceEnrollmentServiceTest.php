<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\Sequence;
use App\Models\Prospector\SequenceEnrollment;
use App\Models\Prospector\SequenceStep;
use App\Models\Prospector\TwilioAccount;
use App\Models\User;
use App\Services\Prospector\SequenceEnrollmentService;

beforeEach(function () {
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
    $this->service = app(SequenceEnrollmentService::class);
});

it('enrolls a contact in a sequence', function () {
    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    $step = SequenceStep::factory()->forSequence($sequence)->sms('Hello')->atOrder(0)->create();
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);

    $enrollment = $this->service->enrollContact($contact, $sequence);

    expect($enrollment)->toBeInstanceOf(SequenceEnrollment::class);
    expect($enrollment->status)->toBe('active');
    expect($enrollment->sequence_id)->toBe($sequence->id);
    expect($enrollment->contact_id)->toBe($contact->id);
    expect($enrollment->current_step_id)->toBe($step->id);
});

it('bulk enrolls contacts skipping already enrolled', function () {
    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    SequenceStep::factory()->forSequence($sequence)->sms('Hello')->atOrder(0)->create();

    $contacts = Contact::factory()->count(5)->create(['company_id' => $this->company->id]);

    // Enroll first 2 manually
    $this->service->enrollContact($contacts[0], $sequence);
    $this->service->enrollContact($contacts[1], $sequence);

    // Bulk enroll all 5 — should only enroll the 3 new ones
    $count = $this->service->bulkEnroll(
        $contacts->pluck('id')->all(),
        $sequence
    );

    expect($count)->toBe(3);
    expect(SequenceEnrollment::where('sequence_id', $sequence->id)->count())->toBe(5);
});

it('unenrolls an enrollment', function () {
    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $enrollment = $this->service->enrollContact($contact, $sequence);

    $this->service->unenroll($enrollment, 'user_requested');

    $enrollment->refresh();
    expect($enrollment->status)->toBe('exited');
    expect($enrollment->exit_reason)->toBe('user_requested');
});

it('pauses an enrollment', function () {
    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $enrollment = $this->service->enrollContact($contact, $sequence);

    $this->service->pauseEnrollment($enrollment);

    $enrollment->refresh();
    expect($enrollment->status)->toBe('paused');
    expect($enrollment->next_execution_at)->toBeNull();
});

it('resumes a paused enrollment', function () {
    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $enrollment = $this->service->enrollContact($contact, $sequence);

    $this->service->pauseEnrollment($enrollment);
    $this->service->resumeEnrollment($enrollment);

    $enrollment->refresh();
    expect($enrollment->status)->toBe('active');
    expect($enrollment->next_execution_at)->not->toBeNull();
});

it('enrolls with an enrolledBy user', function () {
    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    SequenceStep::factory()->forSequence($sequence)->sms('Hello')->atOrder(0)->create();
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $user = User::factory()->internal()->create();

    $enrollment = $this->service->enrollContact($contact, $sequence, $user);

    expect($enrollment->enrolled_by)->toBe($user->id);
});

<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Models\VideoCall;
use Livewire\Livewire;

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->withFullAccess()->create();
    $this->customerUser = User::factory()->customer()->create();
    $this->customer = Customer::factory()->create(['user_id' => $this->customerUser->id]);
    $this->deal = Deal::factory()->create([
        'customer_id' => $this->customer->id,
        'assigned_to' => $this->internalUser->id,
    ]);
    $this->job = Job::factory()->create(['deal_id' => $this->deal->id]);
});

it('renders with Start Call button', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->assertSee('Start Call')
        ->assertDontSee('Return to Call');
});

it('Start Now still creates instant call', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('startCall')
        ->assertSet('showCallPanel', true);

    $videoCall = VideoCall::where('job_id', $this->job->id)
        ->where('status', 'active')
        ->first();

    expect($videoCall)->not->toBeNull()
        ->and($videoCall->initiated_by)->toBe($this->internalUser->id)
        ->and($videoCall->started_at)->not->toBeNull();
});

<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use Livewire\Livewire;

describe('Customer Date Proposal', function () {
    it('can propose a date when no date is set', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'scheduled_date' => null,
        ]);

        $futureDate = now()->addWeeks(2)->format('Y-m-d');

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('No date has been set yet')
            ->set('proposedDate', $futureDate)
            ->call('proposeDate')
            ->assertSee('Your preferred date has been proposed');

        $job->refresh();
        expect($job->scheduled_date->format('Y-m-d'))->toBe($futureDate);
    });

    it('can propose a different date when one exists', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'scheduled_date' => now()->addWeeks(1),
            'company_date_confirmed' => true,
            'customer_date_confirmed' => false,
        ]);

        $newDate = now()->addWeeks(3)->format('Y-m-d');

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Propose a Different Date')
            ->set('proposedDate', $newDate)
            ->call('proposeDate')
            ->assertSee('Your preferred date has been proposed');

        $job->refresh();
        expect($job->scheduled_date->format('Y-m-d'))->toBe($newDate);
    });

    it('resets company confirmation when customer proposes new date', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'scheduled_date' => now()->addWeeks(1),
            'company_date_confirmed' => true,
            'customer_date_confirmed' => false,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->set('proposedDate', now()->addWeeks(3)->format('Y-m-d'))
            ->call('proposeDate');

        $job->refresh();
        expect($job->company_date_confirmed)->toBeFalse();
    });

    it('auto-confirms customer side when proposing', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'scheduled_date' => null,
            'customer_date_confirmed' => false,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->set('proposedDate', now()->addWeeks(2)->format('Y-m-d'))
            ->call('proposeDate');

        $job->refresh();
        expect($job->customer_date_confirmed)->toBeTrue();
    });

    it('sends system message to deal chat thread', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'scheduled_date' => null,
        ]);

        $futureDate = now()->addWeeks(2);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->set('proposedDate', $futureDate->format('Y-m-d'))
            ->call('proposeDate');

        $thread = $deal->fresh()->chatThread;
        expect($thread)->not->toBeNull();

        $systemMessage = $thread->messages()
            ->where('type', ChatMessage::TYPE_SYSTEM)
            ->latest()
            ->first();

        expect($systemMessage)->not->toBeNull();
        expect($systemMessage->content)->toContain('Customer proposed a new start date');
        expect($systemMessage->content)->toContain($futureDate->format('l, F j, Y'));
    });

    it('creates chat thread if none exists when proposing date', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
        ]);

        // Ensure no thread exists
        expect($deal->chatThread)->toBeNull();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->set('proposedDate', now()->addWeeks(2)->format('Y-m-d'))
            ->call('proposeDate');

        // Thread should now exist (created by mount's ensureChatParticipant or proposeDate)
        $deal->refresh();
        expect($deal->chatThread)->not->toBeNull();
    });

    it('validates date is required', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->set('proposedDate', '')
            ->call('proposeDate')
            ->assertHasErrors(['proposedDate' => 'required']);
    });

    it('validates date is not in the past', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->set('proposedDate', now()->subDay()->format('Y-m-d'))
            ->call('proposeDate')
            ->assertHasErrors(['proposedDate']);
    });

    it('shows date picker when no date is set', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'scheduled_date' => null,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Preferred Start Date')
            ->assertSee('Propose Date');
    });

    it('shows propose different date option when date exists and not confirmed', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'scheduled_date' => now()->addWeeks(2),
            'customer_date_confirmed' => false,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Propose a Different Date');
    });

    it('does not show propose different date when customer has confirmed', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
            'scheduled_date' => now()->addWeeks(2),
            'customer_date_confirmed' => true,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertDontSee('Propose a Different Date');
    });
});

describe('Customer Job Progress - Inline Chat', function () {
    it('displays inline chat with project conversation header', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Project Conversation');
    });

    it('shows messages from the deal thread', function () {
        $customerUser = User::factory()->customer()->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
        ]);

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser, $internalUser])
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->withContent('Hello, when works best for you?')
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Hello, when works best for you?');
    });

    it('does not show internal messages to customer', function () {
        $customerUser = User::factory()->customer()->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
        ]);

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser, $internalUser])
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->internal()
            ->withContent('Internal note: customer is difficult')
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->withContent('Looking forward to working with you!')
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertDontSee('Internal note: customer is difficult')
            ->assertSee('Looking forward to working with you!');
    });

    it('shows system messages in chat', function () {
        $customerUser = User::factory()->customer()->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
        ]);

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser, $internalUser])
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->system()
            ->withContent('Customer proposed a new start date: Monday, March 1, 2026')
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Customer proposed a new start date');
    });

    it('shows empty state when no messages', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'estimate_accepted',
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('No messages yet');
    });

    it('auto-creates chat thread and shows conversation on page load', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
        ]);

        // No thread exists yet
        expect($deal->chatThread)->toBeNull();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.job-progress', ['job' => $job])
            ->assertSee('Project Conversation');

        // Thread should now exist
        $deal->refresh();
        expect($deal->chatThread)->not->toBeNull();
        expect($deal->chatThread->hasParticipant($customerUser))->toBeTrue();
    });
});

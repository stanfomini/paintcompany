<?php

use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use App\Notifications\NewContactWelcome;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

describe('Onboarding without property', function () {
    it('does not create a deal or estimate when no property is provided', function () {
        Notification::fake();
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'email' => $customerUser->email,
            'address' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.onboarding')
            ->set('phone', '555-111-2222')
            ->call('nextStep') // step 1 -> 2
            ->call('nextStep') // step 2 -> 3 (no address entered)
            ->call('nextStep') // step 3 -> 4
            ->call('selectEstimatePreference', 'virtual')
            ->assertRedirect(route('customer.dashboard'));

        expect(Deal::where('customer_id', $customer->id)->count())->toBe(0);
        expect(Estimate::whereHas('deal', fn ($q) => $q->where('customer_id', $customer->id))->count())->toBe(0);
    });

    it('creates a support chat thread when no property is provided', function () {
        Notification::fake();
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'email' => $customerUser->email,
            'address' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep')
            ->call('selectEstimatePreference', 'virtual')
            ->assertRedirect(route('customer.dashboard'));

        $thread = ChatThread::where('type', ChatThread::TYPE_SUPPORT)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $customerUser->id))
            ->first();

        expect($thread)->not->toBeNull();
        expect($thread->subject)->toBe($customer->full_name);
    });

    it('sends a welcome system message in the support thread', function () {
        Notification::fake();
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
        Customer::factory()->create([
            'user_id' => $customerUser->id,
            'email' => $customerUser->email,
            'address' => null,
            'city' => null,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep')
            ->call('selectEstimatePreference', 'in_person')
            ->assertRedirect(route('customer.dashboard'));

        $thread = ChatThread::where('type', ChatThread::TYPE_SUPPORT)->first();

        expect($thread->messages()->count())->toBeGreaterThanOrEqual(1);

        $systemMessage = $thread->messages()->where('type', 'system')->first();
        expect($systemMessage)->not->toBeNull();
        expect($systemMessage->content)->toContain('painting project');
    });

    it('adds internal users as chat participants', function () {
        Notification::fake();
        $internalUser1 = User::factory()->internal()->withFullAccess()->create();
        $internalUser2 = User::factory()->internal()->withFullAccess()->create();
        $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
        Customer::factory()->create([
            'user_id' => $customerUser->id,
            'email' => $customerUser->email,
            'address' => null,
            'city' => null,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep')
            ->call('selectEstimatePreference', 'virtual')
            ->assertRedirect(route('customer.dashboard'));

        $thread = ChatThread::where('type', ChatThread::TYPE_SUPPORT)->first();

        expect($thread->hasParticipant($customerUser))->toBeTrue();
        expect($thread->hasParticipant($internalUser1))->toBeTrue();
        expect($thread->hasParticipant($internalUser2))->toBeTrue();
    });

    it('sends NewContactWelcome notification to internal users', function () {
        Notification::fake();
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'email' => $customerUser->email,
            'address' => null,
            'city' => null,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('nextStep')
            ->call('nextStep')
            ->call('selectEstimatePreference', 'virtual')
            ->assertRedirect(route('customer.dashboard'));

        Notification::assertSentTo($internalUser, NewContactWelcome::class, function ($notification) use ($customer) {
            return $notification->customer->id === $customer->id;
        });
    });

    it('still creates deal and estimate when property is provided', function () {
        Notification::fake();
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customerUser = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $customerUser->id,
            'email' => $customerUser->email,
            'address' => null,
            'city' => null,
            'state' => null,
            'zip' => null,
        ]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep') // step 1 -> 2
            ->set('address', '789 Pine St')
            ->set('city', 'Boulder')
            ->set('state', 'CO')
            ->set('zip', '80301')
            ->call('nextStep') // step 2 -> 3
            ->call('nextStep') // step 3 -> 4
            ->call('selectEstimatePreference', 'virtual')
            ->assertRedirect(route('customer.dashboard'));

        $customer->refresh();
        expect($customer->properties()->count())->toBe(1);
        expect(Deal::where('customer_id', $customer->id)->count())->toBe(1);
        expect(Estimate::whereHas('deal', fn ($q) => $q->where('customer_id', $customer->id))->count())->toBe(1);

        // No support chat thread should be created
        expect(ChatThread::where('type', ChatThread::TYPE_SUPPORT)->count())->toBe(0);
    });
});

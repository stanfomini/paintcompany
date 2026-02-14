<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Invitation;
use App\Models\LeadSource;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->withoutVite();

    CompanySetting::clearInstanceCache();
    CompanySetting::getInstance();
    CompanySetting::clearInstanceCache();

    User::factory()->internal()->withFullAccess()->create();
});

describe('Unified CTA flow - Main form', function () {

    it('redirects to invitation accept URL instead of next-steps', function () {
        Notification::fake();

        Livewire::test('public.landing-page')
            ->set('first_name', 'Jane')
            ->set('last_name', 'Doe')
            ->set('email', 'jane-unified@example.com')
            ->set('estimate_preference', 'virtual')
            ->call('submitLeadForm')
            ->assertHasNoErrors()
            ->assertRedirect();

        $invitation = Invitation::where('email', 'jane-unified@example.com')->first();
        expect($invitation)->not->toBeNull();
    });

    it('creates an invitation for the customer', function () {
        Notification::fake();

        Livewire::test('public.landing-page')
            ->set('first_name', 'Invite')
            ->set('last_name', 'Test')
            ->set('email', 'invite-test@example.com')
            ->set('estimate_preference', 'virtual')
            ->call('submitLeadForm');

        $invitation = Invitation::where('email', 'invite-test@example.com')
            ->where('invited_user_type', 'customer')
            ->first();

        expect($invitation)->not->toBeNull()
            ->and($invitation->accepted_at)->toBeNull();
    });

    it('stores prefill data in session', function () {
        Notification::fake();

        Livewire::test('public.landing-page')
            ->set('first_name', 'Session')
            ->set('last_name', 'Test')
            ->set('email', 'session-test@example.com')
            ->set('phone', '303-555-1234')
            ->set('address', '123 Main St')
            ->set('city', 'Denver')
            ->set('state', 'CO')
            ->set('zip', '80202')
            ->set('estimate_preference', 'in_person')
            ->set('description', 'Paint my house')
            ->call('submitLeadForm');

        $prefill = session('landing_page_prefill');
        expect($prefill)->not->toBeNull()
            ->and($prefill['first_name'])->toBe('Session')
            ->and($prefill['last_name'])->toBe('Test')
            ->and($prefill['phone'])->toBe('303-555-1234')
            ->and($prefill['address'])->toBe('123 Main St')
            ->and($prefill['city'])->toBe('Denver')
            ->and($prefill['state'])->toBe('CO')
            ->and($prefill['zip'])->toBe('80202')
            ->and($prefill['estimate_preference'])->toBe('in_person')
            ->and($prefill['description'])->toBe('Paint my house');
    });

    it('redirects existing user to customer login', function () {
        User::factory()->customer()->create(['email' => 'existing-unified@example.com']);

        Livewire::test('public.landing-page')
            ->set('first_name', 'Existing')
            ->set('last_name', 'User')
            ->set('email', 'existing-unified@example.com')
            ->set('estimate_preference', 'virtual')
            ->call('submitLeadForm')
            ->assertRedirect(route('customer.login'));
    });

    it('sends invitation notification', function () {
        Notification::fake();

        Livewire::test('public.landing-page')
            ->set('first_name', 'Notify')
            ->set('last_name', 'Test')
            ->set('email', 'notify-test@example.com')
            ->set('estimate_preference', 'virtual')
            ->call('submitLeadForm');

        $invitation = Invitation::where('email', 'notify-test@example.com')->first();
        expect($invitation)->not->toBeNull();

        Notification::assertSentTo($invitation, \App\Notifications\InvitationNotification::class);
    });

    it('does not create invitation for existing user', function () {
        User::factory()->customer()->create(['email' => 'no-invite-existing@example.com']);

        Livewire::test('public.landing-page')
            ->set('first_name', 'No')
            ->set('last_name', 'Invite')
            ->set('email', 'no-invite-existing@example.com')
            ->set('estimate_preference', 'virtual')
            ->call('submitLeadForm');

        expect(Invitation::where('email', 'no-invite-existing@example.com')->exists())->toBeFalse();
    });
});

describe('Unified CTA flow - Scarcity modal', function () {

    beforeEach(function () {
        $this->seed(\Database\Seeders\LeadSourceSeeder::class);
    });

    it('redirects to invitation accept URL', function () {
        Notification::fake();

        Livewire::test('public.landing-page')
            ->call('openScarcityModal', 'virtual')
            ->set('scarcityEmail', 'scarcity-unified@example.com')
            ->call('submitScarcityLead')
            ->assertHasNoErrors()
            ->assertRedirect();

        $invitation = Invitation::where('email', 'scarcity-unified@example.com')->first();
        expect($invitation)->not->toBeNull();
    });

    it('does not auto-create a User account', function () {
        Notification::fake();

        Livewire::test('public.landing-page')
            ->call('openScarcityModal', 'virtual')
            ->set('scarcityEmail', 'no-auto-user@example.com')
            ->call('submitScarcityLead');

        expect(User::where('email', 'no-auto-user@example.com')->exists())->toBeFalse();
    });

    it('creates customer and deal via ReferralService', function () {
        Notification::fake();

        Livewire::test('public.landing-page')
            ->call('openScarcityModal', 'in_person')
            ->set('scarcityEmail', 'scarcity-customer@example.com')
            ->call('submitScarcityLead');

        $customer = Customer::where('email', 'scarcity-customer@example.com')->first();
        expect($customer)->not->toBeNull()
            ->and($customer->estimate_preference)->toBe('in_person');
    });

    it('sets lead source on customer from scarcity flow', function () {
        Notification::fake();

        Livewire::test('public.landing-page')
            ->call('openScarcityModal', 'virtual')
            ->set('scarcityEmail', 'lead-source@example.com')
            ->call('submitScarcityLead');

        $customer = Customer::where('email', 'lead-source@example.com')->first();
        $leadSource = LeadSource::findBySlug('landing_page_scarcity');
        expect($customer->lead_source_id)->toBe($leadSource->id);
    });

    it('redirects existing user to customer login', function () {
        User::factory()->customer()->create(['email' => 'scarcity-existing@example.com']);

        Livewire::test('public.landing-page')
            ->call('openScarcityModal', 'virtual')
            ->set('scarcityEmail', 'scarcity-existing@example.com')
            ->call('submitScarcityLead')
            ->assertRedirect(route('customer.login'));
    });

    it('stores estimate preference in session', function () {
        Notification::fake();

        Livewire::test('public.landing-page')
            ->call('openScarcityModal', 'in_person')
            ->set('scarcityEmail', 'scarcity-session@example.com')
            ->call('submitScarcityLead');

        $prefill = session('landing_page_prefill');
        expect($prefill)->not->toBeNull()
            ->and($prefill['estimate_preference'])->toBe('in_person');
    });
});

describe('Unified CTA flow - Accept invitation prefill', function () {

    it('prefills name from session data', function () {
        Notification::fake();

        $internal = User::where('type', 'internal')->first();
        $invitationService = app(\App\Services\InvitationService::class);
        $invitation = $invitationService->sendInvitation($internal, 'prefill-name@example.com', 'customer');

        session()->put('landing_page_prefill', [
            'first_name' => 'John',
            'last_name' => 'Smith',
        ]);

        Livewire::test('invitations.accept-invitation', ['token' => $invitation->token])
            ->assertSet('name', 'John Smith')
            ->assertSet('isValid', true);
    });

    it('works without session data', function () {
        Notification::fake();

        $internal = User::where('type', 'internal')->first();
        $invitationService = app(\App\Services\InvitationService::class);
        $invitation = $invitationService->sendInvitation($internal, 'no-prefill@example.com', 'customer');

        Livewire::test('invitations.accept-invitation', ['token' => $invitation->token])
            ->assertSet('name', '')
            ->assertSet('isValid', true);
    });
});

describe('Unified CTA flow - Onboarding prefill', function () {

    it('prefills onboarding fields from session data', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);

        session()->put('landing_page_prefill', [
            'phone' => '303-555-9999',
            'address' => '456 Oak Ave',
            'city' => 'Boulder',
            'state' => 'CO',
            'zip' => '80301',
            'estimate_preference' => 'virtual',
            'description' => 'Exterior paint job',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->assertSet('phone', '303-555-9999')
            ->assertSet('address', '456 Oak Ave')
            ->assertSet('city', 'Boulder')
            ->assertSet('state', 'CO')
            ->assertSet('zip', '80301')
            ->assertSet('estimatePreference', 'virtual')
            ->assertSet('projectNotes', 'Exterior paint job');

        expect(session('landing_page_prefill'))->toBeNull();
    });

    it('does not overwrite existing customer data with session data', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        Customer::factory()->create([
            'user_id' => $user->id,
            'phone' => '720-555-1111',
            'address' => '789 Elm St',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80210',
        ]);

        session()->put('landing_page_prefill', [
            'phone' => '303-555-9999',
            'address' => '456 Oak Ave',
            'city' => 'Boulder',
            'state' => 'CO',
            'zip' => '80301',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->assertSet('phone', '720-555-1111')
            ->assertSet('address', '789 Elm St')
            ->assertSet('city', 'Denver');
    });
});

<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Invitation;
use App\Models\LeadSource;
use App\Models\User;
use Database\Seeders\LeadSourceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(LeadSourceSeeder::class);
    // Internal user needed for created_by on Customer and user_id on Activity
    User::factory()->internal()->create();
});

// --- Lead Sources ---

it('has landing_page_scarcity lead source after seeding', function () {
    $source = LeadSource::findBySlug('landing_page_scarcity');

    expect($source)->not->toBeNull()
        ->and($source->name)->toBe('Landing Page - Availability')
        ->and($source->type)->toBe('built_in')
        ->and($source->is_system)->toBeTrue()
        ->and($source->is_active)->toBeTrue();
});

it('has agent_signup lead source after seeding', function () {
    $source = LeadSource::findBySlug('agent_signup');

    expect($source)->not->toBeNull()
        ->and($source->name)->toBe('Agent Signup')
        ->and($source->type)->toBe('built_in')
        ->and($source->is_system)->toBeTrue()
        ->and($source->is_active)->toBeTrue();
});

// --- Scarcity Lead Capture ---

it('opens scarcity modal with preference', function () {
    Livewire::test('public.landing-page')
        ->call('openScarcityModal', 'virtual')
        ->assertSet('scarcityPreference', 'virtual')
        ->assertSet('showScarcityModal', true);
});

it('opens scarcity modal with in_person preference', function () {
    Livewire::test('public.landing-page')
        ->call('openScarcityModal', 'in_person')
        ->assertSet('scarcityPreference', 'in_person')
        ->assertSet('showScarcityModal', true);
});

it('creates a new customer via scarcity lead submission', function () {
    Livewire::test('public.landing-page')
        ->call('openScarcityModal', 'virtual')
        ->set('scarcityEmail', 'newlead@example.com')
        ->call('submitScarcityLead')
        ->assertRedirect();

    $customer = Customer::where('email', 'newlead@example.com')->first();

    expect($customer)->not->toBeNull()
        ->and($customer->first_name)->toBe('newlead');
});

it('sets lead source and estimate preference on customer from scarcity lead', function () {
    Livewire::test('public.landing-page')
        ->call('openScarcityModal', 'in_person')
        ->set('scarcityEmail', 'scarcitylead@example.com')
        ->call('submitScarcityLead');

    $customer = Customer::where('email', 'scarcitylead@example.com')->first();

    expect($customer)->not->toBeNull()
        ->and($customer->estimate_preference)->toBe('in_person');

    $leadSource = LeadSource::findBySlug('landing_page_scarcity');
    expect($customer->lead_source_id)->toBe($leadSource->id);
});

it('redirects existing user to login on scarcity lead submission', function () {
    User::factory()->customer()->create([
        'email' => 'existing@example.com',
        'needs_onboarding' => true,
    ]);

    Livewire::test('public.landing-page')
        ->call('openScarcityModal', 'virtual')
        ->set('scarcityEmail', 'existing@example.com')
        ->call('submitScarcityLead')
        ->assertRedirect(route('customer.login'));

    expect(User::where('email', 'existing@example.com')->count())->toBe(1);
});

it('redirects scarcity lead to invitation accept URL', function () {
    $component = Livewire::test('public.landing-page')
        ->call('openScarcityModal', 'virtual')
        ->set('scarcityEmail', 'redirect@example.com')
        ->call('submitScarcityLead');

    $invitation = Invitation::where('email', 'redirect@example.com')->first();
    expect($invitation)->not->toBeNull();

    $component->assertRedirect();
});

it('validates email is required for scarcity lead', function () {
    Livewire::test('public.landing-page')
        ->call('openScarcityModal', 'virtual')
        ->set('scarcityEmail', '')
        ->call('submitScarcityLead')
        ->assertHasErrors(['scarcityEmail' => 'required']);
});

it('validates email format for scarcity lead', function () {
    Livewire::test('public.landing-page')
        ->call('openScarcityModal', 'virtual')
        ->set('scarcityEmail', 'not-an-email')
        ->call('submitScarcityLead')
        ->assertHasErrors(['scarcityEmail' => 'email']);
});

// --- Agent Signup ---

it('sends invitation instead of creating user on agent signup', function () {
    Notification::fake();

    Livewire::test('public.landing-page')
        ->set('agentName', 'Jane Smith')
        ->set('agentEmail', 'jane@realty.com')
        ->set('agentCompany', 'Denver Realty')
        ->set('agentPhone', '555-999-1234')
        ->call('submitAgentSignup')
        ->assertRedirect(route('next-steps'));

    // No User created yet - agent sets up account via invitation
    expect(User::where('email', 'jane@realty.com')->exists())->toBeFalse();

    // Invitation created for agent type
    $invitation = Invitation::where('email', 'jane@realty.com')
        ->where('invited_user_type', 'agent')
        ->first();

    expect($invitation)->not->toBeNull()
        ->and($invitation->accepted_at)->toBeNull();
});

it('creates activity for internal team on agent signup', function () {
    Notification::fake();

    Livewire::test('public.landing-page')
        ->set('agentName', 'Activity Agent')
        ->set('agentEmail', 'activity@realty.com')
        ->set('agentCompany', 'Activity Realty')
        ->set('agentPhone', '555-333-4444')
        ->call('submitAgentSignup');

    $activity = Activity::where('title', 'like', '%Activity Agent%')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->type)->toBe('task')
        ->and($activity->title)->toContain('Activity Agent')
        ->and($activity->title)->toContain('Activity Realty')
        ->and($activity->description)->toContain('555-333-4444');
});

it('creates meeting activity when agent provides meeting date', function () {
    Notification::fake();
    $meetingDate = now()->addDays(3)->format('Y-m-d');

    Livewire::test('public.landing-page')
        ->set('agentName', 'Meeting Agent')
        ->set('agentEmail', 'meeting@realty.com')
        ->set('agentCompany', 'Meeting Realty')
        ->set('agentPhone', '555-555-6666')
        ->set('agentMeetingDate', $meetingDate)
        ->call('submitAgentSignup');

    $activity = Activity::where('title', 'like', '%Meeting Agent%')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->type)->toBe('meeting')
        ->and($activity->due_at)->not->toBeNull();
});

it('creates meeting activity with correct time when agent provides date and time', function () {
    Notification::fake();
    $meetingDate = now()->addDays(3)->format('Y-m-d');

    Livewire::test('public.landing-page')
        ->set('agentName', 'Time Agent')
        ->set('agentEmail', 'timeagent@realty.com')
        ->set('agentCompany', 'Time Realty')
        ->set('agentPhone', '555-555-7777')
        ->set('agentMeetingDate', $meetingDate)
        ->set('agentMeetingTime', '14:30')
        ->call('submitAgentSignup');

    $activity = Activity::where('title', 'like', '%Time Agent%')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->type)->toBe('meeting')
        ->and($activity->due_at->format('H:i'))->toBe('14:30')
        ->and($activity->description)->toContain('2:30 PM');
});

it('defaults meeting time to 9am when only date is provided', function () {
    Notification::fake();
    $meetingDate = now()->addDays(3)->format('Y-m-d');

    Livewire::test('public.landing-page')
        ->set('agentName', 'Default Time Agent')
        ->set('agentEmail', 'default@realty.com')
        ->set('agentCompany', 'Default Realty')
        ->set('agentPhone', '555-555-8888')
        ->set('agentMeetingDate', $meetingDate)
        ->call('submitAgentSignup');

    $activity = Activity::where('title', 'like', '%Default Time Agent%')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->due_at->format('H:i'))->toBe('09:00');
});

it('flashes meeting data to session when meeting is scheduled', function () {
    Notification::fake();
    $meetingDate = now()->addDays(3)->format('Y-m-d');

    Livewire::test('public.landing-page')
        ->set('agentName', 'Session Agent')
        ->set('agentEmail', 'session@realty.com')
        ->set('agentCompany', 'Session Realty')
        ->set('agentPhone', '555-555-9999')
        ->set('agentMeetingDate', $meetingDate)
        ->set('agentMeetingTime', '10:00')
        ->call('submitAgentSignup')
        ->assertRedirect(route('next-steps'));

    expect(session('meeting_scheduled'))->not->toBeNull()
        ->and(session('meeting_scheduled')['title'])->toBe('Painting Made Easy Denver - Agent Partnership Meeting')
        ->and(session('meeting_scheduled')['name'])->toBe('Session Agent');
});

it('does not flash meeting data when no date is provided', function () {
    Notification::fake();

    Livewire::test('public.landing-page')
        ->set('agentName', 'No Meeting Agent')
        ->set('agentEmail', 'nomeeting@realty.com')
        ->set('agentCompany', 'No Meeting Realty')
        ->set('agentPhone', '555-555-0000')
        ->call('submitAgentSignup')
        ->assertRedirect(route('next-steps'));

    expect(session('meeting_scheduled'))->toBeNull();
});

it('validates required fields for agent signup', function () {
    Livewire::test('public.landing-page')
        ->set('agentName', '')
        ->set('agentEmail', '')
        ->set('agentCompany', '')
        ->set('agentPhone', '')
        ->call('submitAgentSignup')
        ->assertHasErrors(['agentEmail', 'agentName', 'agentCompany', 'agentPhone']);
});

it('handles existing agent user gracefully on signup', function () {
    Notification::fake();

    User::factory()->agent()->create([
        'email' => 'existingagent@realty.com',
    ]);

    Livewire::test('public.landing-page')
        ->set('agentName', 'Existing Agent')
        ->set('agentEmail', 'existingagent@realty.com')
        ->set('agentCompany', 'Updated Realty')
        ->set('agentPhone', '555-777-8888')
        ->call('submitAgentSignup')
        ->assertRedirect(route('next-steps'));

    // User not duplicated, invitation not created (user already exists)
    expect(User::where('email', 'existingagent@realty.com')->count())->toBe(1);
    expect(Invitation::where('email', 'existingagent@realty.com')->exists())->toBeFalse();
});

// --- Next Steps Calendar ---

it('shows add to calendar buttons when meeting is scheduled', function () {
    $meetingData = [
        'date' => now()->addDays(3)->setTime(14, 0)->toIso8601String(),
        'title' => 'Painting Made Easy Denver - Agent Partnership Meeting',
        'name' => 'Test Agent',
    ];

    session()->flash('meeting_scheduled', $meetingData);

    $this->get(route('next-steps'))
        ->assertSee('Meeting Scheduled')
        ->assertSee('Google Calendar')
        ->assertSee('Apple / Outlook (.ics)');
});

it('does not show calendar section when no meeting is scheduled', function () {
    $this->get(route('next-steps'))
        ->assertDontSee('Meeting Scheduled')
        ->assertDontSee('Google Calendar');
});

it('shows correct meeting date and time on next steps page', function () {
    $meetingDate = now()->addDays(3)->setTime(14, 30);
    $meetingData = [
        'date' => $meetingDate->toIso8601String(),
        'title' => 'Painting Made Easy Denver - Agent Partnership Meeting',
        'name' => 'Test Agent',
    ];

    session()->flash('meeting_scheduled', $meetingData);

    $this->get(route('next-steps'))
        ->assertSee($meetingDate->format('g:i A'));
});

it('generates google calendar url with correct parameters', function () {
    $meetingDate = now()->addDays(3)->setTime(10, 0);

    Livewire::withQueryParams([])
        ->test('public.next-steps')
        ->assertSet('meeting', null);
});

it('downloads ics file for meeting', function () {
    $meetingData = [
        'date' => now()->addDays(3)->setTime(14, 0)->toIso8601String(),
        'title' => 'Painting Made Easy Denver - Agent Partnership Meeting',
        'name' => 'Test Agent',
    ];

    $response = Livewire::test('public.next-steps')
        ->set('meeting', $meetingData)
        ->call('downloadIcs');

    $response->assertFileDownloaded('meeting.ics');
});

// --- Customer Onboarding Query Params ---

it('sets step from query param on customer onboarding', function () {
    $user = User::factory()->customer()->create(['needs_onboarding' => true]);

    Livewire::actingAs($user, 'customer')
        ->withQueryParams(['step' => '3'])
        ->test('customer.onboarding')
        ->assertSet('step', 3);
});

it('sets preference from query param on customer onboarding', function () {
    $user = User::factory()->customer()->create(['needs_onboarding' => true]);

    Livewire::actingAs($user, 'customer')
        ->withQueryParams(['preference' => 'in_person'])
        ->test('customer.onboarding')
        ->assertSet('estimatePreference', 'in_person');
});

it('sets both step and preference from query params', function () {
    $user = User::factory()->customer()->create(['needs_onboarding' => true]);

    Livewire::actingAs($user, 'customer')
        ->withQueryParams(['step' => '4', 'preference' => 'virtual'])
        ->test('customer.onboarding')
        ->assertSet('step', 4)
        ->assertSet('estimatePreference', 'virtual');
});

it('ignores invalid step query param', function () {
    $user = User::factory()->customer()->create(['needs_onboarding' => true]);

    Livewire::actingAs($user, 'customer')
        ->withQueryParams(['step' => '99'])
        ->test('customer.onboarding')
        ->assertSet('step', 1);
});

it('ignores invalid preference query param', function () {
    $user = User::factory()->customer()->create(['needs_onboarding' => true]);

    Livewire::actingAs($user, 'customer')
        ->withQueryParams(['preference' => 'invalid'])
        ->test('customer.onboarding')
        ->assertSet('estimatePreference', '');
});

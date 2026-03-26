<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Invitation;
use App\Models\Property;
use App\Models\User;
use App\Services\TwilioService;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->internal()->create());

    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(false);
    app()->instance(TwilioService::class, $twilio);
});

it('creates agent with correct field mapping (tier 1 - top only)', function () {
    Livewire::test('communications.quick-add-agent')
        ->set('first_name', 'Jane')
        ->set('last_name', 'Realtor')
        ->set('email', 'jane@realty.com')
        ->set('phone', '3035551111')
        ->set('company_name', 'RE/MAX Denver')
        ->call('submitQuickAdd');

    $user = User::where('email', 'jane@realty.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Jane Realtor')
        ->and($user->type)->toBe('agent')
        ->and($user->needs_onboarding)->toBeTrue();

    $profile = $user->agentProfile;
    expect($profile)->not->toBeNull()
        ->and($profile->company_name)->toBe('RE/MAX Denver')
        ->and($profile->phone)->toBe('3035551111');
});

it('creates invitation with existing_user_id for agent', function () {
    Livewire::test('communications.quick-add-agent')
        ->set('first_name', 'Invite')
        ->set('last_name', 'Agent')
        ->set('email', 'invite@agent.com')
        ->set('phone', '3035552222')
        ->call('submitQuickAdd');

    $user = User::where('email', 'invite@agent.com')->first();
    $invitation = Invitation::where('email', 'invite@agent.com')->first();

    expect($invitation)->not->toBeNull()
        ->and($invitation->existing_user_id)->toBe($user->id)
        ->and($invitation->invited_user_type)->toBe('agent');
});

it('creates property, deal, and estimate when full form is filled (tier 2)', function () {
    Livewire::test('communications.quick-add-agent')
        ->set('first_name', 'Full')
        ->set('last_name', 'Agent')
        ->set('email', 'full@agent.com')
        ->set('phone', '3035553333')
        ->set('company_name', 'Keller Williams')
        ->set('address', '123 Main St')
        ->set('city', 'Denver')
        ->set('state', 'CO')
        ->set('zip', '80202')
        ->set('listing_price', '450000')
        ->set('intent', 'selling')
        ->call('submitQuickAdd');

    $user = User::where('email', 'full@agent.com')->first();

    expect($user->needs_onboarding)->toBeFalse();

    $property = Property::where('address', '123 Main St')->first();
    expect($property)->not->toBeNull()
        ->and($property->city)->toBe('Denver')
        ->and($property->agent_id)->toBe($user->id);

    $deal = Deal::where('property_id', $property->id)->first();
    expect($deal)->not->toBeNull()
        ->and($deal->stage)->toBe('lead')
        ->and($deal->agent_id)->toBe($user->id);

    $estimate = Estimate::where('deal_id', $deal->id)->first();
    expect($estimate)->not->toBeNull()
        ->and($estimate->status)->toBe('draft');
});

it('sets needs_onboarding to true when only top fields filled (tier 1)', function () {
    Livewire::test('communications.quick-add-agent')
        ->set('first_name', 'Basic')
        ->set('last_name', 'Agent')
        ->set('email', 'basic@agent.com')
        ->call('submitQuickAdd');

    $user = User::where('email', 'basic@agent.com')->first();

    expect($user->needs_onboarding)->toBeTrue();
    expect(Property::count())->toBe(0);
    expect(Deal::where('agent_id', $user->id)->count())->toBe(0);
});

it('sends SMS with invite link when phone provided', function () {
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldReceive('sendSms')
        ->once()
        ->withArgs(fn ($to, $body) => str_contains($body, '/invitation/accept/'))
        ->andReturn(['success' => true, 'sid' => 'SM456']);
    app()->instance(TwilioService::class, $twilio);

    Livewire::test('communications.quick-add-agent')
        ->set('first_name', 'Sms')
        ->set('last_name', 'Agent')
        ->set('email', 'sms@agent.com')
        ->set('phone', '3035554444')
        ->call('submitQuickAdd');
});

it('shows success modal after creation', function () {
    Livewire::test('communications.quick-add-agent')
        ->set('first_name', 'Success')
        ->set('last_name', 'Agent')
        ->set('email', 'success@agent.com')
        ->call('submitQuickAdd')
        ->assertSet('showSuccessModal', true)
        ->assertSet('showModal', false);
});

it('pre-populates agent onboarding with profile data from quick-add', function () {
    $user = User::factory()->agent()->create([
        'needs_onboarding' => true,
    ]);
    $user->agentProfile->update([
        'company_name' => 'Pre-filled Brokerage',
        'phone' => '3035557777',
    ]);

    $this->actingAs($user, 'agent');

    Livewire::test('agent.onboarding')
        ->assertSet('companyName', 'Pre-filled Brokerage')
        ->assertSet('phone', '3035557777');
});

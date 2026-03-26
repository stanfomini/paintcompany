<?php

use App\Models\Invitation;
use App\Models\User;
use App\Services\TwilioService;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->internal()->create());
});

it('creates crew user with correct field mapping', function () {
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(false);
    app()->instance(TwilioService::class, $twilio);

    Livewire::test('communications.quick-add-crew')
        ->set('first_name', 'John')
        ->set('last_name', 'Painter')
        ->set('email', 'john@crew.com')
        ->set('phone', '3035551234')
        ->set('company_name', 'JP Painting')
        ->call('submitQuickAdd');

    $user = User::where('email', 'john@crew.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('John Painter')
        ->and($user->type)->toBe('crew')
        ->and($user->needs_onboarding)->toBeTrue();

    $profile = $user->crewProfile;
    expect($profile)->not->toBeNull()
        ->and($profile->company_name)->toBe('JP Painting')
        ->and($profile->phone)->toBe('3035551234');
});

it('creates invitation with existing_user_id for crew', function () {
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(false);
    app()->instance(TwilioService::class, $twilio);

    Livewire::test('communications.quick-add-crew')
        ->set('first_name', 'Jane')
        ->set('last_name', 'Crew')
        ->set('email', 'jane@crew.com')
        ->set('phone', '3035555678')
        ->set('company_name', 'JC Painting')
        ->call('submitQuickAdd');

    $user = User::where('email', 'jane@crew.com')->first();
    $invitation = Invitation::where('email', 'jane@crew.com')->first();

    expect($invitation)->not->toBeNull()
        ->and($invitation->existing_user_id)->toBe($user->id)
        ->and($invitation->invited_user_type)->toBe('crew');
});

it('sends SMS with invite link when phone is provided', function () {
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldReceive('sendSms')
        ->once()
        ->withArgs(function ($to, $body) {
            return str_contains($to, '3035559999') && str_contains($body, '/invitation/accept/');
        })
        ->andReturn(['success' => true, 'sid' => 'SM123']);
    app()->instance(TwilioService::class, $twilio);

    Livewire::test('communications.quick-add-crew')
        ->set('first_name', 'Sms')
        ->set('last_name', 'Test')
        ->set('email', 'sms@crew.com')
        ->set('phone', '3035559999')
        ->call('submitQuickAdd');
});

it('does not send SMS when phone is empty', function () {
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldNotReceive('sendSms');
    app()->instance(TwilioService::class, $twilio);

    Livewire::test('communications.quick-add-crew')
        ->set('first_name', 'No')
        ->set('last_name', 'Phone')
        ->set('email', 'nophone@crew.com')
        ->call('submitQuickAdd');
});

it('shows success modal after creation', function () {
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(false);
    app()->instance(TwilioService::class, $twilio);

    Livewire::test('communications.quick-add-crew')
        ->set('first_name', 'Success')
        ->set('last_name', 'Modal')
        ->set('email', 'success@crew.com')
        ->call('submitQuickAdd')
        ->assertSet('showSuccessModal', true)
        ->assertSet('showModal', false);
});

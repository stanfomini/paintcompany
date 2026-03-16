<?php

use App\Models\CallLog;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\TwilioService;
use Livewire\Livewire;
use Twilio\Rest\Api\V2010\Account\CallContext;
use Twilio\Rest\Api\V2010\Account\CallInstance;
use Twilio\Rest\Client;

beforeEach(function () {
    CompanySetting::clearInstanceCache();
    config([
        'services.twilio.enabled' => true,
        'services.twilio.sid' => 'test-sid',
        'services.twilio.token' => 'test-token',
        'services.twilio.from' => '+15551234567',
        'services.twilio.voice_enabled' => true,
    ]);

    $this->twilioMock = $this->partialMock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true)->byDefault();
    });

    $this->callInstanceMock = Mockery::mock(CallInstance::class);

    $this->user = User::factory()->internal()->create([
        'forwarding_number' => '+13035551111',
        'extension' => '100',
    ]);
    $this->user->givePermissionTo(['phone.make_calls', 'phone.transfer_calls']);
    $this->actingAs($this->user);
});

afterEach(function () {
    CompanySetting::clearInstanceCache();
});

// ===================================================================
// Default state
// ===================================================================

describe('default state', function () {
    it('renders for users with phone.make_calls permission', function () {
        Livewire::test('communications.active-call')
            ->assertStatus(200);
    });

    it('starts inactive by default', function () {
        Livewire::test('communications.active-call')
            ->assertSet('isActive', false)
            ->assertSet('callLogId', null)
            ->assertSet('callLogUlid', null)
            ->assertSet('callerName', null)
            ->assertSet('callerNumber', null)
            ->assertSet('customerId', null)
            ->assertSet('showTransferPicker', false);
    });

    it('does not show active call bar when inactive', function () {
        Livewire::test('communications.active-call')
            ->assertDontSee('Active Call')
            ->assertDontSee('Hang Up');
    });
});

// ===================================================================
// onCallConnected handler
// ===================================================================

describe('onCallConnected', function () {
    it('activates overlay with call data', function () {
        Livewire::test('communications.active-call')
            ->call('onCallConnected', [
                'call_log_id' => 42,
                'ulid' => 'abc123',
                'caller_name' => 'John Doe',
                'from_number' => '+13035559999',
                'customer_id' => 7,
            ])
            ->assertSet('isActive', true)
            ->assertSet('callLogId', 42)
            ->assertSet('callLogUlid', 'abc123')
            ->assertSet('callerName', 'John Doe')
            ->assertSet('callerNumber', '+13035559999')
            ->assertSet('customerId', 7)
            ->assertSet('showTransferPicker', false);
    });

    it('shows caller name in overlay', function () {
        Livewire::test('communications.active-call')
            ->call('onCallConnected', [
                'call_log_id' => 42,
                'caller_name' => 'John Doe',
                'from_number' => '+13035559999',
            ])
            ->assertSee('John Doe')
            ->assertSee('+13035559999');
    });

    it('shows phone number when no caller name', function () {
        Livewire::test('communications.active-call')
            ->call('onCallConnected', [
                'call_log_id' => 42,
                'from_number' => '+13035559999',
            ])
            ->assertSee('+13035559999');
    });

    it('shows Active Call fallback when no caller info', function () {
        Livewire::test('communications.active-call')
            ->call('onCallConnected', [
                'call_log_id' => 42,
            ])
            ->assertSee('Active Call');
    });

    it('resets transfer picker when new call connects', function () {
        Livewire::test('communications.active-call')
            ->set('showTransferPicker', true)
            ->call('onCallConnected', [
                'call_log_id' => 42,
            ])
            ->assertSet('showTransferPicker', false);
    });

    it('shows hang up button when active', function () {
        Livewire::test('communications.active-call')
            ->call('onCallConnected', [
                'call_log_id' => 42,
            ])
            ->assertSee('Hang Up');
    });
});

// ===================================================================
// onCallEnded handler
// ===================================================================

describe('onCallEnded', function () {
    it('resets state when matching call ends', function () {
        Livewire::test('communications.active-call')
            ->call('onCallConnected', [
                'call_log_id' => 42,
                'ulid' => 'abc123',
                'caller_name' => 'John Doe',
                'from_number' => '+13035559999',
            ])
            ->assertSet('isActive', true)
            ->call('onCallEnded', ['call_log_id' => 42])
            ->assertSet('isActive', false)
            ->assertSet('callLogId', null)
            ->assertSet('callerName', null)
            ->assertSet('callerNumber', null);
    });

    it('ignores ended event for different call', function () {
        Livewire::test('communications.active-call')
            ->call('onCallConnected', [
                'call_log_id' => 42,
                'caller_name' => 'John Doe',
            ])
            ->call('onCallEnded', ['call_log_id' => 99])
            ->assertSet('isActive', true)
            ->assertSet('callLogId', 42)
            ->assertSet('callerName', 'John Doe');
    });

    it('hides overlay when call ends', function () {
        Livewire::test('communications.active-call')
            ->call('onCallConnected', ['call_log_id' => 42])
            ->assertSee('Hang Up')
            ->call('onCallEnded', ['call_log_id' => 42])
            ->assertDontSee('Hang Up');
    });
});

// ===================================================================
// Transfer picker
// ===================================================================

describe('transfer picker', function () {
    it('toggles transfer picker visibility', function () {
        Livewire::test('communications.active-call')
            ->call('onCallConnected', ['call_log_id' => 42])
            ->assertSet('showTransferPicker', false)
            ->call('toggleTransferPicker')
            ->assertSet('showTransferPicker', true)
            ->call('toggleTransferPicker')
            ->assertSet('showTransferPicker', false);
    });

    it('shows transfer button for users with transfer permission', function () {
        Livewire::test('communications.active-call')
            ->call('onCallConnected', ['call_log_id' => 42])
            ->assertSee('Transfer');
    });

    it('hides transfer button for users without transfer permission', function () {
        $user = User::factory()->internal()->create();
        $user->givePermissionTo('phone.make_calls');
        // Not giving phone.transfer_calls
        $this->actingAs($user);

        Livewire::test('communications.active-call')
            ->call('onCallConnected', ['call_log_id' => 42])
            ->assertDontSee('Transfer');
    });

    it('shows internal users with extensions in transfer picker', function () {
        User::factory()->internal()->create([
            'name' => 'Alice Transfer',
            'extension' => '201',
            'forwarding_number' => '+13035553333',
        ]);

        Livewire::test('communications.active-call')
            ->call('onCallConnected', ['call_log_id' => 42])
            ->call('toggleTransferPicker')
            ->assertSee('Alice Transfer')
            ->assertSee('x201');
    });

    it('excludes current user from transfer list', function () {
        Livewire::test('communications.active-call')
            ->call('onCallConnected', ['call_log_id' => 42])
            ->call('toggleTransferPicker')
            ->assertDontSee('x100');
    });

    it('excludes users without extensions from transfer list', function () {
        User::factory()->internal()->create([
            'name' => 'No Extension User',
            'extension' => null,
            'forwarding_number' => '+13035554444',
        ]);

        $component = Livewire::test('communications.active-call');
        $users = $component->invade()->internalUsers;

        expect($users->pluck('name')->toArray())
            ->not->toContain('No Extension User');
    });

    it('excludes users without forwarding numbers from transfer list', function () {
        User::factory()->internal()->create([
            'name' => 'No Phone User',
            'extension' => '202',
            'forwarding_number' => null,
        ]);

        $component = Livewire::test('communications.active-call');
        $users = $component->invade()->internalUsers;

        expect($users->pluck('name')->toArray())
            ->not->toContain('No Phone User');
    });

    it('shows no users available message when list is empty', function () {
        // Remove all users except current
        User::where('id', '!=', $this->user->id)->delete();

        Livewire::test('communications.active-call')
            ->call('onCallConnected', ['call_log_id' => 42])
            ->call('toggleTransferPicker')
            ->assertSee('No users available');
    });
});

// ===================================================================
// Hangup
// ===================================================================

describe('hangup', function () {
    it('does nothing when no active call', function () {
        Livewire::test('communications.active-call')
            ->call('hangup')
            ->assertSet('isActive', false);
    });

    it('resets state after hangup', function () {
        $callLog = CallLog::factory()->create([
            'status' => 'in-progress',
            'twilio_call_sid' => 'pending-test123',
        ]);

        Livewire::test('communications.active-call')
            ->call('onCallConnected', [
                'call_log_id' => $callLog->id,
                'caller_name' => 'John Doe',
            ])
            ->assertSet('isActive', true)
            ->call('hangup')
            ->assertSet('isActive', false)
            ->assertSet('callLogId', null)
            ->assertSet('callerName', null);
    });

    it('updates call log to completed', function () {
        $callLog = CallLog::factory()->create([
            'status' => 'in-progress',
            'twilio_call_sid' => 'pending-hangup',
            'ended_at' => null,
        ]);

        Livewire::test('communications.active-call')
            ->call('onCallConnected', ['call_log_id' => $callLog->id])
            ->call('hangup');

        $callLog->refresh();
        expect($callLog->status)->toBe('completed')
            ->and($callLog->ended_at)->not->toBeNull();
    });
});

// ===================================================================
// Transfer action
// ===================================================================

describe('transfer action', function () {
    it('does nothing when no active call', function () {
        Livewire::test('communications.active-call')
            ->call('transfer', '101')
            ->assertSet('isActive', false);
    });

    it('dispatches error when call log not found', function () {
        Livewire::test('communications.active-call')
            ->set('callLogId', 999999)
            ->call('transfer', '101')
            ->assertDispatched('notify');
    });

    it('dispatches error for invalid extension', function () {
        $callLog = CallLog::factory()->create([
            'status' => 'in-progress',
            'twilio_call_sid' => 'CAbadext'.str_repeat('z', 25),
        ]);

        Livewire::test('communications.active-call')
            ->call('onCallConnected', ['call_log_id' => $callLog->id])
            ->call('transfer', '999')
            ->assertDispatched('notify')
            ->assertSet('isActive', true);
    });

    it('resets state after successful transfer', function () {
        $callLog = CallLog::factory()->create([
            'status' => 'in-progress',
            'twilio_call_sid' => 'CAxfer'.str_repeat('m', 28),
            'answered_by_user_id' => $this->user->id,
        ]);

        User::factory()->internal()->create([
            'extension' => '103',
            'forwarding_number' => '+13035555555',
        ]);

        $callContext = Mockery::mock(CallContext::class);
        $callContext->shouldReceive('update')->once()->andReturn($this->callInstanceMock);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('calls')
            ->with('CAxfer'.str_repeat('m', 28))
            ->andReturn($callContext);

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        Livewire::test('communications.active-call')
            ->call('onCallConnected', ['call_log_id' => $callLog->id])
            ->assertSet('isActive', true)
            ->call('transfer', '103')
            ->assertSet('isActive', false)
            ->assertSet('callLogId', null);
    });
});

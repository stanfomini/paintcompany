<?php

use App\Models\CallGroup;
use App\Models\CompanySetting;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    CompanySetting::clearInstanceCache();
    $this->admin = User::factory()->internal()->create();
    $this->admin->givePermissionTo('settings.phone_system');
    $this->actingAs($this->admin);
});

afterEach(function () {
    CompanySetting::clearInstanceCache();
});

// --- Access Control ---

it('loads the phone system settings page for authorized user', function () {
    $this->get('/settings/phone-system')
        ->assertSuccessful()
        ->assertSee('General Settings');
});

it('returns 403 for user without settings.phone_system permission', function () {
    $user = User::factory()->internal()->create();
    $this->actingAs($user);

    $this->get('/settings/phone-system')
        ->assertForbidden();
});

it('returns 403 for non-internal user', function () {
    $customer = User::factory()->customer()->create();
    $customer->givePermissionTo('settings.phone_system');
    $this->actingAs($customer);

    Livewire::test('pages::settings.phone-system')
        ->assertForbidden();
});

// --- Section 1: General Settings ---

it('can enable the phone system', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('phoneSystemEnabled', true)
        ->call('saveGeneral')
        ->assertDispatched('general-saved');

    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->phone_system_enabled)->toBeTrue();
});

it('can update routing mode to smart_routing', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('phoneRoutingMode', 'smart_routing')
        ->call('saveGeneral')
        ->assertDispatched('general-saved');

    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->phone_routing_mode)->toBe('smart_routing');
});

it('can enable call recording', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('callRecordingEnabled', true)
        ->call('saveGeneral')
        ->assertDispatched('general-saved');

    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->call_recording_enabled)->toBeTrue();
});

it('can update TTS voice in general settings', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ttsVoice', 'Polly.Matthew')
        ->call('saveGeneral')
        ->assertDispatched('general-saved');

    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->tts_voice)->toBe('Polly.Matthew');
});

it('validates TTS voice must be a valid option', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ttsVoice', 'InvalidVoice')
        ->call('saveGeneral')
        ->assertHasErrors(['ttsVoice']);
});

// --- Section 2: Call Groups ---

it('can create a new call group', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('groupName', 'Sales Team')
        ->set('groupDescription', 'Handles sales calls')
        ->set('ringTimeout', 20)
        ->call('saveGroup')
        ->assertDispatched('call-groups-saved');

    $group = CallGroup::where('name', 'Sales Team')->first();
    expect($group)->not->toBeNull()
        ->and($group->description)->toBe('Handles sales calls')
        ->and($group->ring_timeout_seconds)->toBe(20)
        ->and($group->is_active)->toBeTrue();
});

it('can edit an existing call group', function () {
    $group = CallGroup::factory()->create(['name' => 'Old Name']);

    Livewire::test('pages::settings.phone-system')
        ->call('editGroup', $group->id)
        ->assertSet('editingGroupId', $group->id)
        ->assertSet('groupName', 'Old Name')
        ->set('groupName', 'New Name')
        ->set('ringTimeout', 45)
        ->call('saveGroup')
        ->assertDispatched('call-groups-saved');

    $group->refresh();
    expect($group->name)->toBe('New Name')
        ->and($group->ring_timeout_seconds)->toBe(45);
});

it('can add members to a call group', function () {
    $member1 = User::factory()->internal()->create(['forwarding_number' => '+15551111111']);
    $member2 = User::factory()->internal()->create(['forwarding_number' => '+15552222222']);

    Livewire::test('pages::settings.phone-system')
        ->set('groupName', 'Support Team')
        ->set('ringTimeout', 30)
        ->call('addMember', $member1->id)
        ->call('addMember', $member2->id)
        ->assertSet('groupMembers', function ($members) use ($member1, $member2) {
            return count($members) === 2
                && $members[0]['user_id'] === $member1->id
                && $members[1]['user_id'] === $member2->id;
        })
        ->call('saveGroup')
        ->assertDispatched('call-groups-saved');

    $group = CallGroup::where('name', 'Support Team')->first();
    expect($group->members)->toHaveCount(2);
});

it('prevents adding duplicate members', function () {
    $member = User::factory()->internal()->create();

    Livewire::test('pages::settings.phone-system')
        ->call('addMember', $member->id)
        ->call('addMember', $member->id)
        ->assertSet('groupMembers', function ($members) {
            return count($members) === 1;
        });
});

it('can remove members from a call group', function () {
    $member1 = User::factory()->internal()->create();
    $member2 = User::factory()->internal()->create();

    Livewire::test('pages::settings.phone-system')
        ->call('addMember', $member1->id)
        ->call('addMember', $member2->id)
        ->call('removeMember', 0)
        ->assertSet('groupMembers', function ($members) use ($member2) {
            return count($members) === 1
                && $members[0]['user_id'] === $member2->id;
        });
});

it('can delete a call group', function () {
    $group = CallGroup::factory()->create();

    Livewire::test('pages::settings.phone-system')
        ->call('confirmDeleteGroup', $group->id)
        ->assertSet('deletingGroupId', $group->id)
        ->call('deleteGroup')
        ->assertDispatched('call-groups-saved');

    expect(CallGroup::find($group->id))->toBeNull();
});

it('can set default call group', function () {
    $group = CallGroup::factory()->create();

    Livewire::test('pages::settings.phone-system')
        ->set('defaultCallGroupId', $group->id)
        ->call('saveCallGroupDefaults')
        ->assertDispatched('call-group-defaults-saved');

    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->default_call_group_id)->toBe($group->id);
});

it('can set after-hours call group', function () {
    $group = CallGroup::factory()->create();

    Livewire::test('pages::settings.phone-system')
        ->set('afterHoursCallGroupId', $group->id)
        ->call('saveCallGroupDefaults')
        ->assertDispatched('call-group-defaults-saved');

    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->after_hours_call_group_id)->toBe($group->id);
});

it('validates group name is required', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('groupName', '')
        ->set('ringTimeout', 30)
        ->call('saveGroup')
        ->assertHasErrors(['groupName' => 'required']);
});

// --- Section 3: Business Hours ---

it('can update business hours', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('alwaysOpen', false)
        ->set('businessHoursStart', '09:00')
        ->set('businessHoursEnd', '18:00')
        ->set('businessDays', [1, 2, 3, 4, 5])
        ->call('saveBusinessHours')
        ->assertDispatched('business-hours-saved');

    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    expect($settings->phone_business_hours_start)->toBe('09:00')
        ->and($settings->phone_business_hours_end)->toBe('18:00')
        ->and($settings->phone_business_days)->toBe([1, 2, 3, 4, 5]);
});

it('can set always open to clear business hours', function () {
    $settings = CompanySetting::getInstance();
    $settings->update([
        'phone_business_hours_start' => '08:00',
        'phone_business_hours_end' => '17:00',
        'phone_business_days' => [1, 2, 3, 4, 5],
    ]);
    CompanySetting::clearInstanceCache();

    Livewire::test('pages::settings.phone-system')
        ->set('alwaysOpen', true)
        ->call('saveBusinessHours')
        ->assertDispatched('business-hours-saved');

    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    expect($settings->phone_business_hours_start)->toBeNull()
        ->and($settings->phone_business_hours_end)->toBeNull()
        ->and($settings->phone_business_days)->toBeNull();
});

it('can toggle business days', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('alwaysOpen', false)
        ->set('businessHoursStart', '08:00')
        ->set('businessHoursEnd', '17:00')
        ->set('businessDays', [1, 2, 3, 4, 5, 6])
        ->call('saveBusinessHours')
        ->assertDispatched('business-hours-saved');

    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->phone_business_days)->toBe([1, 2, 3, 4, 5, 6]);
});

// --- Section 4: IVR Menu ---

it('can enable IVR and add menu options', function () {
    $group = CallGroup::factory()->create();

    Livewire::test('pages::settings.phone-system')
        ->set('ivrEnabled', true)
        ->call('addIvrOption')
        ->assertSet('ivrOptions', function ($options) {
            return count($options) === 1 && $options[0]['digit'] === '1';
        })
        ->set('ivrOptions.0.label', 'Estimates')
        ->set('ivrOptions.0.call_group_id', $group->id)
        ->call('saveIvr')
        ->assertDispatched('ivr-saved');

    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    expect($settings->ivr_enabled)->toBeTrue()
        ->and($settings->ivr_menu['options'])->toHaveCount(1)
        ->and($settings->ivr_menu['options'][0]['label'])->toBe('Estimates')
        ->and($settings->ivr_menu['options'][0]['digit'])->toBe('1');
});

it('can remove IVR menu options', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ivrEnabled', true)
        ->call('addIvrOption')
        ->call('addIvrOption')
        ->assertSet('ivrOptions', function ($options) {
            return count($options) === 2;
        })
        ->call('removeIvrOption', 0)
        ->assertSet('ivrOptions', function ($options) {
            return count($options) === 1;
        });
});

it('assigns next available digit when adding IVR options', function () {
    Livewire::test('pages::settings.phone-system')
        ->call('addIvrOption')
        ->call('addIvrOption')
        ->call('addIvrOption')
        ->assertSet('ivrOptions', function ($options) {
            return $options[0]['digit'] === '1'
                && $options[1]['digit'] === '2'
                && $options[2]['digit'] === '3';
        });
});

it('can set IVR timeout action', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ivrEnabled', true)
        ->set('timeoutAction', 'voicemail')
        ->set('ivrOptions', [])
        ->call('saveIvr')
        ->assertDispatched('ivr-saved');

    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->ivr_menu['timeout_action'])->toBe('voicemail');
});

it('can save IVR greeting type as tts with custom text', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ivrEnabled', true)
        ->set('ivrGreetingType', 'tts')
        ->set('ivrGreetingText', 'Welcome to our company.')
        ->call('saveIvr')
        ->assertDispatched('ivr-saved');

    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    expect($settings->ivr_greeting_type)->toBe('tts')
        ->and($settings->ivr_greeting_text)->toBe('Welcome to our company.');
});

it('can save IVR greeting type as auto', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ivrEnabled', true)
        ->set('ivrGreetingType', 'auto')
        ->call('saveIvr')
        ->assertDispatched('ivr-saved');

    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->ivr_greeting_type)->toBe('auto');
});

it('validates IVR greeting type must be valid option', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ivrGreetingType', 'invalid')
        ->call('saveIvr')
        ->assertHasErrors(['ivrGreetingType']);
});

it('validates IVR option labels are required', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ivrEnabled', true)
        ->set('ivrOptions', [
            ['digit' => '1', 'label' => '', 'call_group_id' => null],
        ])
        ->call('saveIvr')
        ->assertHasErrors(['ivrOptions.0.label' => 'required']);
});

// --- Section 5: Voicemail ---

it('can update voicemail greeting text', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('voicemailGreetingType', 'tts')
        ->set('voicemailGreetingText', 'Thanks for calling Painting Made Easy')
        ->set('voicemailTimeoutSeconds', 30)
        ->call('saveVoicemail')
        ->assertDispatched('voicemail-saved');

    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    expect($settings->voicemail_greeting_type)->toBe('tts')
        ->and($settings->voicemail_greeting_text)->toBe('Thanks for calling Painting Made Easy')
        ->and($settings->voicemail_timeout_seconds)->toBe(30);
});

it('can update voicemail timeout seconds', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('voicemailTimeoutSeconds', 45)
        ->call('saveVoicemail')
        ->assertDispatched('voicemail-saved');

    CompanySetting::clearInstanceCache();
    expect(CompanySetting::getInstance()->voicemail_timeout_seconds)->toBe(45);
});

it('validates voicemail timeout is within range', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('voicemailTimeoutSeconds', 5)
        ->call('saveVoicemail')
        ->assertHasErrors(['voicemailTimeoutSeconds']);
});

// --- Section 6: Extensions ---

it('can update user extension with valid 3-digit value', function () {
    $user = User::factory()->internal()->create(['extension' => null]);

    Livewire::test('pages::settings.phone-system')
        ->call('startEditExtension', $user->id)
        ->assertSet('editingExtensionUserId', $user->id)
        ->set('editingExtension', '101')
        ->call('saveExtension')
        ->assertDispatched('extensions-saved');

    $user->refresh();
    expect($user->extension)->toBe('101');
});

it('can update user extension with valid 4-digit value', function () {
    $user = User::factory()->internal()->create(['extension' => null]);

    Livewire::test('pages::settings.phone-system')
        ->call('startEditExtension', $user->id)
        ->set('editingExtension', '1001')
        ->call('saveExtension')
        ->assertDispatched('extensions-saved');

    $user->refresh();
    expect($user->extension)->toBe('1001');
});

it('rejects duplicate extensions', function () {
    $user1 = User::factory()->internal()->create(['extension' => '101']);
    $user2 = User::factory()->internal()->create(['extension' => null]);

    Livewire::test('pages::settings.phone-system')
        ->call('startEditExtension', $user2->id)
        ->set('editingExtension', '101')
        ->call('saveExtension')
        ->assertHasErrors(['editingExtension']);
});

it('allows user to keep their own extension', function () {
    $user = User::factory()->internal()->create(['extension' => '101']);

    Livewire::test('pages::settings.phone-system')
        ->call('startEditExtension', $user->id)
        ->assertSet('editingExtension', '101')
        ->set('editingExtension', '101')
        ->call('saveExtension')
        ->assertHasNoErrors();
});

it('rejects non-numeric extension values', function () {
    $user = User::factory()->internal()->create();

    Livewire::test('pages::settings.phone-system')
        ->call('startEditExtension', $user->id)
        ->set('editingExtension', 'abc')
        ->call('saveExtension')
        ->assertHasErrors(['editingExtension']);
});

it('rejects extension with fewer than 3 digits', function () {
    $user = User::factory()->internal()->create();

    Livewire::test('pages::settings.phone-system')
        ->call('startEditExtension', $user->id)
        ->set('editingExtension', '12')
        ->call('saveExtension')
        ->assertHasErrors(['editingExtension']);
});

it('can update user forwarding number', function () {
    $user = User::factory()->internal()->create(['forwarding_number' => null]);

    Livewire::test('pages::settings.phone-system')
        ->call('startEditExtension', $user->id)
        ->set('editingForwardingNumber', '(303) 555-1234')
        ->call('saveExtension')
        ->assertDispatched('extensions-saved');

    $user->refresh();
    expect($user->forwarding_number)->toBe('(303) 555-1234');
});

it('rejects invalid forwarding number format', function () {
    $user = User::factory()->internal()->create();

    Livewire::test('pages::settings.phone-system')
        ->call('startEditExtension', $user->id)
        ->set('editingForwardingNumber', 'not-a-phone')
        ->call('saveExtension')
        ->assertHasErrors(['editingForwardingNumber']);
});

it('can cancel editing extension', function () {
    $user = User::factory()->internal()->create(['extension' => '101']);

    Livewire::test('pages::settings.phone-system')
        ->call('startEditExtension', $user->id)
        ->assertSet('editingExtensionUserId', $user->id)
        ->call('cancelEditExtension')
        ->assertSet('editingExtensionUserId', null)
        ->assertSet('editingExtension', '');
});

it('can clear extension by saving empty value', function () {
    $user = User::factory()->internal()->create(['extension' => '101']);

    Livewire::test('pages::settings.phone-system')
        ->call('startEditExtension', $user->id)
        ->set('editingExtension', '')
        ->call('saveExtension')
        ->assertDispatched('extensions-saved');

    $user->refresh();
    expect($user->extension)->toBeNull();
});

// --- Ring Timeout Defaults ---

it('defaults ring timeout to 17 seconds', function () {
    Livewire::test('pages::settings.phone-system')
        ->assertSet('ringTimeout', 17);
});

it('shows cell phone voicemail warning when ring timeout exceeds 20 seconds', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ringTimeout', 25)
        ->assertSee('Most cell phones route to voicemail after 20-25 seconds');
});

it('does not show cell phone voicemail warning when ring timeout is 20 or under', function () {
    Livewire::test('pages::settings.phone-system')
        ->set('ringTimeout', 17)
        ->assertDontSee('Most cell phones route to voicemail after 20-25 seconds');
});

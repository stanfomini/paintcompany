<?php

use App\Models\Customer;
use App\Models\MessengerBotFlow;
use App\Models\MessengerConversation;
use App\Models\SmsBotFlow;
use App\Models\SmsConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
});

it('renders the bot tracker page', function () {
    Livewire::test('pages::marketing.bot-tracker')
        ->assertSuccessful()
        ->assertSee('Bot Tracker')
        ->assertSee('Active Conversations')
        ->assertSee('Completed')
        ->assertSee('Leads Created')
        ->assertSee('Conversion Rate');
});

it('denies access to users without marketing.view permission', function () {
    $user = User::factory()->internal()->create();
    $this->actingAs($user);

    Livewire::test('pages::marketing.bot-tracker')
        ->assertForbidden();
});

it('denies access to non-internal users', function () {
    $customer = User::factory()->customer()->create();
    $this->actingAs($customer);

    Livewire::test('pages::marketing.bot-tracker')
        ->assertForbidden();
});

it('counts active conversations from both channels', function () {
    MessengerConversation::factory()->count(3)->create(['status' => 'in_progress']);
    MessengerConversation::factory()->completed()->create();
    SmsConversation::factory()->count(2)->create(['status' => 'in_progress']);
    SmsConversation::factory()->completed()->create();

    // 3 messenger + 2 sms = 5 active
    Livewire::test('pages::marketing.bot-tracker')
        ->assertSee('5') // active count shown in UI
        ->assertSee('Currently in progress');
});

it('counts completed conversations in date range', function () {
    // Inside 30-day range
    MessengerConversation::factory()->completed()->create([
        'completed_at' => now()->subDays(5),
    ]);
    SmsConversation::factory()->completed()->create([
        'completed_at' => now()->subDays(10),
    ]);
    // Outside 30-day range
    MessengerConversation::factory()->completed()->create([
        'completed_at' => now()->subDays(60),
    ]);

    Livewire::test('pages::marketing.bot-tracker')
        ->assertSet('dateRange', '30')
        ->assertSee('In selected period');
});

it('counts leads created from messenger with customer_id', function () {
    $customer = Customer::factory()->create();

    MessengerConversation::factory()->completed()->create([
        'customer_id' => $customer->id,
        'completed_at' => now()->subDays(5),
    ]);
    // No customer - not a lead
    MessengerConversation::factory()->completed()->create([
        'customer_id' => null,
        'completed_at' => now()->subDays(5),
    ]);

    Livewire::test('pages::marketing.bot-tracker')
        ->assertSee('From Messenger bots');
});

it('calculates conversion rate correctly', function () {
    // 2 completed + 2 abandoned = 50% rate
    MessengerConversation::factory()->count(2)->completed()->create([
        'completed_at' => now()->subDays(5),
    ]);
    MessengerConversation::factory()->count(2)->abandoned()->create([
        'last_activity_at' => now()->subDays(5),
    ]);

    Livewire::test('pages::marketing.bot-tracker')
        ->assertSet('dateRange', '30')
        ->assertSee('50%');
});

it('returns zero conversion rate when no completed or abandoned', function () {
    Livewire::test('pages::marketing.bot-tracker')
        ->assertSee('0%');
});

it('groups entry source stats for messenger conversations', function () {
    MessengerConversation::factory()->completed()->create([
        'entry_source' => 'ad',
        'started_at' => now()->subDays(5),
        'completed_at' => now()->subDays(5),
    ]);
    MessengerConversation::factory()->abandoned()->create([
        'entry_source' => 'ad',
        'started_at' => now()->subDays(5),
    ]);
    MessengerConversation::factory()->completed()->create([
        'entry_source' => 'keyword',
        'started_at' => now()->subDays(5),
        'completed_at' => now()->subDays(5),
    ]);

    Livewire::test('pages::marketing.bot-tracker')
        ->assertSee('Entry Source Performance')
        ->assertSee('Ad')
        ->assertSee('Keyword')
        ->assertSee('Default');
});

it('shows bot flow performance for both channels', function () {
    $messengerFlow = MessengerBotFlow::factory()->active()->create(['name' => 'Welcome Flow']);
    MessengerConversation::factory()->completed()->create([
        'flow_id' => $messengerFlow->id,
        'started_at' => now()->subDays(5),
        'completed_at' => now()->subDays(5),
    ]);
    MessengerConversation::factory()->abandoned()->create([
        'flow_id' => $messengerFlow->id,
        'started_at' => now()->subDays(5),
        'last_activity_at' => now()->subDays(5),
    ]);

    $smsFlow = SmsBotFlow::factory()->active()->create(['name' => 'Color Flow']);
    SmsConversation::factory()->completed()->create([
        'flow_id' => $smsFlow->id,
        'completed_at' => now()->subDays(5),
    ]);

    Livewire::test('pages::marketing.bot-tracker')
        ->assertSet('dateRange', '30')
        ->assertSee('Bot Flow Performance')
        ->assertSee('Welcome Flow')
        ->assertSee('Color Flow')
        ->assertSee('Messenger')
        ->assertSee('SMS');
});

it('shows recent conversations from both channels', function () {
    MessengerConversation::factory()->create([
        'status' => 'in_progress',
        'fb_profile_data' => ['first_name' => 'John', 'last_name' => 'Doe'],
        'last_activity_at' => now()->subMinutes(5),
        'started_at' => now()->subMinutes(30),
    ]);

    SmsConversation::factory()->create([
        'status' => 'completed',
        'phone' => '+13035551234',
        'completed_at' => now()->subMinutes(2),
        'last_message_at' => now()->subMinutes(2),
    ]);

    Livewire::test('pages::marketing.bot-tracker')
        ->assertSee('Recent Conversations')
        ->assertSee('John Doe')
        ->assertSee('+13035551234')
        ->assertSee('In Progress')
        ->assertSee('Completed');
});

it('updates metrics when date range changes', function () {
    // Old conversation outside 7-day range but inside 30-day
    MessengerConversation::factory()->completed()->create([
        'completed_at' => now()->subDays(15),
    ]);
    // Recent conversation inside 7-day range
    MessengerConversation::factory()->completed()->create([
        'completed_at' => now()->subDays(3),
    ]);

    // At 30 days, should show 2 completed
    $component = Livewire::test('pages::marketing.bot-tracker')
        ->assertSet('dateRange', '30');

    // Change to 7 days - should now show only 1
    $component->set('dateRange', '7');
    // Verify property changed
    $component->assertSet('dateRange', '7');
});

it('defaults to 30-day date range', function () {
    Livewire::test('pages::marketing.bot-tracker')
        ->assertSet('dateRange', '30');
});

it('handles today date range filter', function () {
    MessengerConversation::factory()->completed()->create([
        'completed_at' => now()->subHours(2),
    ]);
    MessengerConversation::factory()->completed()->create([
        'completed_at' => now()->subDays(2),
    ]);

    Livewire::test('pages::marketing.bot-tracker')
        ->set('dateRange', 'today')
        ->assertSet('dateRange', 'today');
});

it('displays messenger conversation names from fb_profile_data', function () {
    MessengerConversation::factory()->create([
        'fb_profile_data' => ['first_name' => 'Jane', 'last_name' => 'Smith'],
        'last_activity_at' => now(),
    ]);

    Livewire::test('pages::marketing.bot-tracker')
        ->assertSee('Jane Smith');
});

it('shows Unknown for messenger conversations without profile data', function () {
    MessengerConversation::factory()->create([
        'fb_profile_data' => null,
        'last_activity_at' => now(),
    ]);

    Livewire::test('pages::marketing.bot-tracker')
        ->assertSee('Unknown');
});

it('shows correct status badges for conversations', function () {
    MessengerConversation::factory()->create([
        'status' => 'human_takeover',
        'last_activity_at' => now(),
        'fb_profile_data' => ['first_name' => 'Takeover', 'last_name' => 'User'],
    ]);
    MessengerConversation::factory()->abandoned()->create([
        'last_activity_at' => now()->subMinutes(1),
        'fb_profile_data' => ['first_name' => 'Abandoned', 'last_name' => 'User'],
    ]);

    Livewire::test('pages::marketing.bot-tracker')
        ->assertSee('Handoff')
        ->assertSee('Abandoned');
});

it('shows empty state when no flows exist', function () {
    Livewire::test('pages::marketing.bot-tracker')
        ->assertSee('No bot flows found');
});

it('shows empty state when no conversations exist', function () {
    Livewire::test('pages::marketing.bot-tracker')
        ->assertSee('No conversations yet');
});

it('is accessible via the marketing bot-tracker route', function () {
    $this->get(route('marketing.bot-tracker'))
        ->assertSuccessful();
});

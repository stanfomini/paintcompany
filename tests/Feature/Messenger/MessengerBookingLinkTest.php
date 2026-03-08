<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Services\MessengerLeadCreationService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    MessengerSetting::clearInstanceCache();
    $this->assignedUser = User::factory()->internal()->create();
    $settings = MessengerSetting::getInstance();
    $settings->update(['default_assigned_to' => $this->assignedUser->id]);
    MessengerSetting::clearInstanceCache();
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

describe('booking token', function () {
    it('generates a valid booking token', function () {
        $conversation = MessengerConversation::factory()->create();

        $token = $conversation->generateBookingToken();

        expect($token)->toHaveLength(64);
        expect($conversation->booking_token)->toBe($token);
        expect($conversation->booking_token_expires_at)->not->toBeNull();
        expect($conversation->isBookingTokenValid())->toBeTrue();
    });

    it('generates booking URL from token', function () {
        $conversation = MessengerConversation::factory()->create();
        $conversation->generateBookingToken();

        $url = $conversation->getBookingUrl();

        expect($url)->toContain('/book/messenger/');
        expect($url)->toContain($conversation->booking_token);
    });

    it('returns null for booking URL when no token exists', function () {
        $conversation = MessengerConversation::factory()->create();

        expect($conversation->getBookingUrl())->toBeNull();
    });

    it('detects expired booking token', function () {
        $conversation = MessengerConversation::factory()->create();
        $conversation->generateBookingToken();
        $conversation->update(['booking_token_expires_at' => now()->subDay()]);

        expect($conversation->fresh()->isBookingTokenValid())->toBeFalse();
    });

    it('invalidates booking token', function () {
        $conversation = MessengerConversation::factory()->create();
        $conversation->generateBookingToken();
        expect($conversation->isBookingTokenValid())->toBeTrue();

        $conversation->invalidateBookingToken();

        expect($conversation->booking_token)->toBeNull();
        expect($conversation->booking_token_expires_at)->toBeNull();
        expect($conversation->isBookingTokenValid())->toBeFalse();
    });
});

describe('public booking page', function () {
    it('loads the booking form with valid token', function () {
        $flow = MessengerBotFlow::factory()->active()->create();
        $conversation = MessengerConversation::factory()->create([
            'flow_id' => $flow->id,
            'fb_profile_data' => ['first_name' => 'John', 'last_name' => 'Doe'],
        ]);
        $conversation->generateBookingToken();

        $response = $this->get("/book/messenger/{$conversation->booking_token}");

        $response->assertOk();
        $response->assertSee('Get My Free Estimate');
    });

    it('shows expired message for invalid token', function () {
        $response = $this->get('/book/messenger/invalidtoken123');

        $response->assertOk();
        $response->assertSee('Link Expired');
    });

    it('shows expired message for expired token', function () {
        $conversation = MessengerConversation::factory()->create();
        $conversation->generateBookingToken();
        $conversation->update(['booking_token_expires_at' => now()->subDay()]);

        $response = $this->get("/book/messenger/{$conversation->booking_token}");

        $response->assertOk();
        $response->assertSee('Link Expired');
    });

    it('shows submitted message when conversation already has customer', function () {
        $customer = Customer::factory()->create();
        $conversation = MessengerConversation::factory()->create([
            'customer_id' => $customer->id,
        ]);
        $conversation->generateBookingToken();

        $response = $this->get("/book/messenger/{$conversation->booking_token}");

        $response->assertOk();
        $response->assertSee('Thank You');
    });

    it('pre-fills form with collected data and FB profile', function () {
        $flow = MessengerBotFlow::factory()->active()->create();
        $step = MessengerBotStep::factory()->textQuestion('customer.email')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
        ]);

        $conversation = MessengerConversation::factory()->create([
            'flow_id' => $flow->id,
            'fb_profile_data' => ['first_name' => 'Jane', 'last_name' => 'Smith'],
            'answers' => ["step_{$step->id}" => 'jane@example.com'],
        ]);
        $conversation->generateBookingToken();

        $response = $this->get("/book/messenger/{$conversation->booking_token}");

        $response->assertOk();
        $response->assertSee('jane@example.com');
    });

    it('submits form and creates customer, deal, and estimate', function () {
        Notification::fake();

        $flow = MessengerBotFlow::factory()->active()->create();
        $conversation = MessengerConversation::factory()->create([
            'flow_id' => $flow->id,
        ]);
        $conversation->generateBookingToken();
        $token = $conversation->booking_token;

        Livewire\Livewire::test('public.messenger-booking', ['token' => $token])
            ->set('first_name', 'Alice')
            ->set('last_name', 'Wonder')
            ->set('email', 'alice@example.com')
            ->set('phone', '555-111-2222')
            ->call('submit')
            ->assertSet('submitted', true);

        $conversation->refresh();
        expect($conversation->customer_id)->not->toBeNull();
        expect($conversation->deal_id)->not->toBeNull();
        expect($conversation->booking_token)->toBeNull();

        $customer = Customer::find($conversation->customer_id);
        expect($customer->first_name)->toBe('Alice');
        expect($customer->last_name)->toBe('Wonder');
        expect($customer->email)->toBe('alice@example.com');

        $deal = Deal::find($conversation->deal_id);
        expect($deal)->not->toBeNull();
        expect($deal->stage)->toBe('lead');

        // Should have created a draft estimate
        $estimate = Estimate::where('deal_id', $deal->id)->first();
        expect($estimate)->not->toBeNull();
        expect($estimate->status)->toBe('draft');
    });

    it('rejects honeypot submissions', function () {
        $conversation = MessengerConversation::factory()->create();
        $conversation->generateBookingToken();

        Livewire\Livewire::test('public.messenger-booking', ['token' => $conversation->booking_token])
            ->set('first_name', 'Bot')
            ->set('website', 'http://spam.com')
            ->call('submit')
            ->assertSet('submitted', false);

        $conversation->refresh();
        expect($conversation->customer_id)->toBeNull();
    });
});

describe('fb profile fallback', function () {
    it('uses Facebook profile name when bot did not collect name', function () {
        Notification::fake();

        // Flow without a name step
        $flow = MessengerBotFlow::factory()->active()->create();
        MessengerBotStep::factory()->textQuestion('customer.email')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
        ]);

        $steps = $flow->steps()->orderBy('sort_order')->get();

        $conversation = MessengerConversation::factory()->completed()->create([
            'flow_id' => $flow->id,
            'fb_profile_data' => ['first_name' => 'Facebook', 'last_name' => 'User'],
            'answers' => ["step_{$steps[0]->id}" => 'fb@example.com'],
        ]);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['customer']->first_name)->toBe('Facebook');
        expect($result['customer']->last_name)->toBe('User');
    });

    it('prefers bot-collected name over Facebook profile', function () {
        Notification::fake();

        $flow = MessengerBotFlow::factory()->active()->create();
        $step1 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
        ]);
        $step2 = MessengerBotStep::factory()->textQuestion('customer.last_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
        ]);

        $conversation = MessengerConversation::factory()->completed()->create([
            'flow_id' => $flow->id,
            'fb_profile_data' => ['first_name' => 'Facebook', 'last_name' => 'Name'],
            'answers' => [
                "step_{$step1->id}" => 'Bot',
                "step_{$step2->id}" => 'Collected',
            ],
        ]);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['customer']->first_name)->toBe('Bot');
        expect($result['customer']->last_name)->toBe('Collected');
    });
});

describe('auto-create draft estimate', function () {
    it('creates draft estimate when conversation completes', function () {
        Notification::fake();

        $flow = MessengerBotFlow::factory()->active()->create();
        $step = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
        ]);

        $conversation = MessengerConversation::factory()->completed()->create([
            'flow_id' => $flow->id,
            'answers' => ["step_{$step->id}" => 'EstimateTest'],
        ]);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result)->toHaveKey('estimate');
        expect($result['estimate'])->toBeInstanceOf(Estimate::class);
        expect($result['estimate']->status)->toBe('draft');
        expect($result['estimate']->deal_id)->toBe($result['deal']->id);
        expect($result['estimate']->estimate_number)->not->toBeEmpty();
    });
});

describe('manual conversion', function () {
    it('creates customer, deal, and estimate from manual conversion', function () {
        Notification::fake();

        $conversation = MessengerConversation::factory()->abandoned()->create();

        $service = new MessengerLeadCreationService;
        $result = $service->createFromManualConversion($conversation, [
            'first_name' => 'Manual',
            'last_name' => 'Convert',
            'email' => 'manual@test.com',
            'phone' => '555-999-8888',
            'notes' => 'Interior repaint needed',
        ]);

        expect($result['customer']->first_name)->toBe('Manual');
        expect($result['customer']->last_name)->toBe('Convert');
        expect($result['deal']->stage)->toBe('lead');
        expect($result['deal']->description)->toBe('Interior repaint needed');
        expect($result['estimate']->status)->toBe('draft');
        expect($result['is_new_customer'])->toBeTrue();

        $conversation->refresh();
        expect($conversation->customer_id)->toBe($result['customer']->id);
        expect($conversation->deal_id)->toBe($result['deal']->id);
    });

    it('links to existing customer when email matches', function () {
        Notification::fake();

        $existing = Customer::factory()->create(['email' => 'existing@test.com']);
        $conversation = MessengerConversation::factory()->abandoned()->create();

        $service = new MessengerLeadCreationService;
        $result = $service->createFromManualConversion($conversation, [
            'first_name' => 'Updated',
            'email' => 'existing@test.com',
        ]);

        expect($result['is_new_customer'])->toBeFalse();
        expect($result['customer']->id)->toBe($existing->id);
    });
});

describe('abandoned conversations', function () {
    it('does not create customer when conversation is abandoned', function () {
        $flow = MessengerBotFlow::factory()->active()->create();
        $conversation = MessengerConversation::factory()->create([
            'flow_id' => $flow->id,
            'status' => 'in_progress',
        ]);

        app(\App\Services\MessengerBotFlowService::class)->abandonConversation($conversation);

        $conversation->refresh();
        expect($conversation->status)->toBe('abandoned');
        expect($conversation->customer_id)->toBeNull();
    });
});

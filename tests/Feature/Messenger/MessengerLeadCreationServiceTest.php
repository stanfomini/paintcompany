<?php

use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\LeadSource;
use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Notifications\MessengerHumanTakeoverNeeded;
use App\Notifications\NewMessengerLead;
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

function createFlowWithSteps(array $stepMappings): MessengerBotFlow
{
    $flow = MessengerBotFlow::factory()->active()->create();

    foreach ($stepMappings as $order => $mapping) {
        MessengerBotStep::factory()
            ->textQuestion($mapping)
            ->create([
                'flow_id' => $flow->id,
                'sort_order' => $order,
            ]);
    }

    return $flow;
}

function createConversationWithAnswers(MessengerBotFlow $flow, array $answers, string $status = 'completed'): MessengerConversation
{
    $steps = $flow->steps()->orderBy('sort_order')->get();
    $formattedAnswers = [];

    $answerValues = array_values($answers);
    foreach ($steps as $index => $step) {
        if (isset($answerValues[$index])) {
            $formattedAnswers["step_{$step->id}"] = $answerValues[$index];
        }
    }

    return MessengerConversation::factory()
        ->state([
            'flow_id' => $flow->id,
            'status' => $status,
            'answers' => $formattedAnswers,
            'completed_at' => $status === 'completed' ? now() : null,
        ])
        ->create();
}

describe('createFromConversation', function () {
    it('creates new customer from complete bot conversation', function () {
        Notification::fake();

        $flow = createFlowWithSteps([
            'customer.first_name',
            'customer.last_name',
            'customer.email',
            'customer.phone',
        ]);

        $conversation = createConversationWithAnswers($flow, [
            'Jane',
            'Doe',
            'jane@example.com',
            '555-123-4567',
        ]);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['is_new_customer'])->toBeTrue();
        expect($result['customer'])->toBeInstanceOf(Customer::class);
        expect($result['customer']->first_name)->toBe('Jane');
        expect($result['customer']->last_name)->toBe('Doe');
        expect($result['customer']->email)->toBe('jane@example.com');
        expect($result['customer']->phone)->toBe('555-123-4567');
        expect($result['customer']->status)->toBe('active');
        expect($result['customer']->source)->toBe('facebook_messenger');
    });

    it('creates deal linked to customer with correct stage and title', function () {
        Notification::fake();

        $flow = createFlowWithSteps(['customer.first_name', 'customer.last_name']);
        $conversation = createConversationWithAnswers($flow, ['John', 'Smith']);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['deal'])->toBeInstanceOf(Deal::class);
        expect($result['deal']->customer_id)->toBe($result['customer']->id);
        expect($result['deal']->stage)->toBe('lead');
        expect($result['deal']->title)->toBe('John Smith - Facebook Lead');
        expect($result['deal']->lead_source)->toBe('Facebook Messenger');
    });

    it('sets lead_source to Facebook Messenger and creates LeadSource record', function () {
        Notification::fake();

        $flow = createFlowWithSteps(['customer.first_name']);
        $conversation = createConversationWithAnswers($flow, ['Alex']);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        $leadSource = LeadSource::where('name', 'Facebook Messenger')->first();
        expect($leadSource)->not->toBeNull();
        expect($leadSource->is_active)->toBeTrue();
        expect($leadSource->color)->toBe('#0084ff');
        expect($result['customer']->lead_source_id)->toBe($leadSource->id);
        expect($result['deal']->lead_source_id)->toBe($leadSource->id);
    });

    it('maps all customer fields correctly', function () {
        Notification::fake();

        $flow = createFlowWithSteps([
            'customer.first_name',
            'customer.last_name',
            'customer.email',
            'customer.phone',
            'customer.address',
            'customer.city',
            'customer.state',
            'customer.zip',
        ]);

        $conversation = createConversationWithAnswers($flow, [
            'Maria',
            'Garcia',
            'maria@test.com',
            '212-555-0100',
            '123 Main St',
            'Springfield',
            'IL',
            '62704',
        ]);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        $customer = $result['customer'];
        expect($customer->first_name)->toBe('Maria');
        expect($customer->last_name)->toBe('Garcia');
        expect($customer->email)->toBe('maria@test.com');
        expect($customer->phone)->toBe('212-555-0100');
        expect($customer->address)->toBe('123 Main St');
        expect($customer->city)->toBe('Springfield');
        expect($customer->state)->toBe('IL');
        expect($customer->zip)->toBe('62704');
    });

    it('maps deal fields correctly', function () {
        Notification::fake();

        $flow = createFlowWithSteps([
            'customer.first_name',
            'deal.title',
            'deal.description',
        ]);

        $conversation = createConversationWithAnswers($flow, [
            'Bob',
            'Kitchen Repaint',
            'Need full kitchen repainted white',
        ]);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['deal']->title)->toBe('Kitchen Repaint');
        expect($result['deal']->description)->toBe('Need full kitchen repainted white');
    });

    it('finds existing customer by email match and does not create duplicate', function () {
        Notification::fake();

        $existingCustomer = Customer::factory()->create([
            'email' => 'existing@example.com',
            'first_name' => 'Old',
            'last_name' => 'Name',
        ]);

        $flow = createFlowWithSteps(['customer.first_name', 'customer.email']);
        $conversation = createConversationWithAnswers($flow, ['Updated', 'existing@example.com']);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['is_new_customer'])->toBeFalse();
        expect($result['customer']->id)->toBe($existingCustomer->id);
        expect(Customer::where('email', 'existing@example.com')->count())->toBe(1);
    });

    it('finds existing customer by phone match with normalization', function () {
        Notification::fake();

        $existingCustomer = Customer::factory()->create([
            'phone' => '(555) 987-6543',
            'email' => 'phone-match@test.com',
        ]);

        $flow = createFlowWithSteps(['customer.first_name', 'customer.phone']);
        $conversation = createConversationWithAnswers($flow, ['Updated', '5559876543']);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['is_new_customer'])->toBeFalse();
        expect($result['customer']->id)->toBe($existingCustomer->id);
    });

    it('updates existing customer with new non-empty fields', function () {
        Notification::fake();

        $existingCustomer = Customer::factory()->create([
            'email' => 'update-me@example.com',
            'first_name' => 'Original',
            'phone' => null,
        ]);

        $flow = createFlowWithSteps(['customer.first_name', 'customer.email', 'customer.phone']);
        $conversation = createConversationWithAnswers($flow, ['Updated', 'update-me@example.com', '555-000-1111']);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['customer']->fresh()->first_name)->toBe('Updated');
        expect($result['customer']->fresh()->phone)->toBe('555-000-1111');
    });

    it('creates new deal even for existing customer', function () {
        Notification::fake();

        $existingCustomer = Customer::factory()->create(['email' => 'returning@example.com']);
        $existingDeal = Deal::factory()->create(['customer_id' => $existingCustomer->id]);

        $flow = createFlowWithSteps(['customer.email', 'customer.first_name']);
        $conversation = createConversationWithAnswers($flow, ['returning@example.com', $existingCustomer->first_name]);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['deal']->id)->not->toBe($existingDeal->id);
        expect($existingCustomer->deals()->count())->toBe(2);
    });

    it('handles incomplete conversation with partial data', function () {
        Notification::fake();

        $flow = createFlowWithSteps([
            'customer.first_name',
            'customer.last_name',
            'customer.email',
            'customer.phone',
        ]);

        // Only first two steps answered before abandon
        $steps = $flow->steps()->orderBy('sort_order')->get();
        $conversation = MessengerConversation::factory()
            ->abandoned()
            ->create([
                'flow_id' => $flow->id,
                'answers' => [
                    "step_{$steps[0]->id}" => 'Partial',
                    "step_{$steps[1]->id}" => 'User',
                ],
            ]);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['customer']->first_name)->toBe('Partial');
        expect($result['customer']->last_name)->toBe('User');
        expect($result['customer']->email)->toBeNull();
    });

    it('includes "(Incomplete)" in deal title for abandoned conversations', function () {
        Notification::fake();

        $flow = createFlowWithSteps(['customer.first_name', 'customer.last_name']);
        $conversation = createConversationWithAnswers($flow, ['Abandoned', 'User'], 'abandoned');

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['deal']->title)->toBe('Abandoned User - Facebook Lead (Incomplete)');
    });

    it('includes "(Incomplete)" in deal title for human_takeover conversations', function () {
        Notification::fake();

        $flow = createFlowWithSteps(['customer.first_name', 'customer.last_name']);
        $conversation = createConversationWithAnswers($flow, ['Taken', 'Over'], 'human_takeover');

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['deal']->title)->toContain('(Incomplete)');
    });

    it('links conversation to created customer and deal', function () {
        Notification::fake();

        $flow = createFlowWithSteps(['customer.first_name', 'customer.email']);
        $conversation = createConversationWithAnswers($flow, ['Linked', 'linked@test.com']);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        $conversation->refresh();
        expect($conversation->customer_id)->toBe($result['customer']->id);
        expect($conversation->deal_id)->toBe($result['deal']->id);
    });

    it('links chat thread customer_id', function () {
        Notification::fake();

        $thread = ChatThread::factory()->facebook()->create(['customer_id' => null]);

        $flow = createFlowWithSteps(['customer.first_name', 'customer.email']);
        $conversation = createConversationWithAnswers($flow, ['ThreadLink', 'thread@test.com']);
        $conversation->update(['thread_id' => $thread->id]);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($thread->fresh()->customer_id)->toBe($result['customer']->id);
    });

    it('assigns deal to default user from MessengerSetting', function () {
        Notification::fake();

        $flow = createFlowWithSteps(['customer.first_name']);
        $conversation = createConversationWithAnswers($flow, ['Assigned']);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['deal']->assigned_to)->toBe($this->assignedUser->id);
        expect($result['deal']->created_by)->toBe($this->assignedUser->id);
    });

    it('handles edge case with no mapped fields at all', function () {
        Notification::fake();

        // Flow with steps that have no field_mapping
        $flow = MessengerBotFlow::factory()->active()->create();
        MessengerBotStep::factory()->greeting()->create(['flow_id' => $flow->id, 'sort_order' => 0]);

        $conversation = MessengerConversation::factory()
            ->completed()
            ->create([
                'flow_id' => $flow->id,
                'answers' => [],
            ]);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['customer'])->toBeInstanceOf(Customer::class);
        expect($result['deal'])->toBeInstanceOf(Deal::class);
        expect($result['is_new_customer'])->toBeTrue();
        expect($result['deal']->title)->toBe('Unknown - Facebook Lead');
    });

    it('handles edge case with only email collected', function () {
        Notification::fake();

        $flow = createFlowWithSteps(['customer.email']);
        $conversation = createConversationWithAnswers($flow, ['onlyemail@test.com']);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['customer']->email)->toBe('onlyemail@test.com');
        expect($result['customer']->first_name)->toBe('Unknown');
        expect($result['is_new_customer'])->toBeTrue();
    });

    it('handles edge case with only phone collected', function () {
        Notification::fake();

        $flow = createFlowWithSteps(['customer.phone']);
        $conversation = createConversationWithAnswers($flow, ['555-111-2222']);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect($result['customer']->phone)->toBe('555-111-2222');
        expect($result['is_new_customer'])->toBeTrue();
    });

    it('reuses existing Facebook Messenger lead source if it exists', function () {
        Notification::fake();

        $existingSource = LeadSource::create([
            'name' => 'Facebook Messenger',
            'is_active' => true,
            'color' => '#0084ff',
        ]);

        $flow = createFlowWithSteps(['customer.first_name']);
        $conversation = createConversationWithAnswers($flow, ['Reuse']);

        $service = new MessengerLeadCreationService;
        $result = $service->createFromConversation($conversation);

        expect(LeadSource::where('name', 'Facebook Messenger')->count())->toBe(1);
        expect($result['customer']->lead_source_id)->toBe($existingSource->id);
    });
});

describe('notifications', function () {
    it('sends NewMessengerLead notification on complete conversation', function () {
        Notification::fake();

        $flow = createFlowWithSteps(['customer.first_name', 'customer.email']);
        $conversation = createConversationWithAnswers($flow, ['Notified', 'notified@test.com']);

        $service = new MessengerLeadCreationService;
        $service->createFromConversation($conversation);

        Notification::assertSentTo(
            $this->assignedUser,
            NewMessengerLead::class,
            function ($notification) {
                return $notification->isIncomplete === false
                    && $notification->customer->first_name === 'Notified';
            }
        );
    });

    it('sends notification about incomplete lead for abandoned conversations', function () {
        Notification::fake();

        $flow = createFlowWithSteps(['customer.first_name']);
        $conversation = createConversationWithAnswers($flow, ['Abandoned'], 'abandoned');

        $service = new MessengerLeadCreationService;
        $service->createFromConversation($conversation);

        Notification::assertSentTo(
            $this->assignedUser,
            NewMessengerLead::class,
            function ($notification) {
                return $notification->isIncomplete === true;
            }
        );
    });

    it('sends notification about incomplete lead for human_takeover conversations', function () {
        Notification::fake();

        $flow = createFlowWithSteps(['customer.first_name']);
        $conversation = createConversationWithAnswers($flow, ['Takeover'], 'human_takeover');

        $service = new MessengerLeadCreationService;
        $service->createFromConversation($conversation);

        Notification::assertSentTo(
            $this->assignedUser,
            NewMessengerLead::class,
            function ($notification) {
                return $notification->isIncomplete === true;
            }
        );
    });

    it('does not send notification when no default_assigned_to is set', function () {
        Notification::fake();

        $settings = MessengerSetting::getInstance();
        $settings->update(['default_assigned_to' => null]);
        MessengerSetting::clearInstanceCache();

        $flow = createFlowWithSteps(['customer.first_name']);
        $conversation = createConversationWithAnswers($flow, ['NoAssign']);

        $service = new MessengerLeadCreationService;
        $service->createFromConversation($conversation);

        Notification::assertNothingSent();
    });
});

describe('createPartialLead', function () {
    it('delegates to createFromConversation for abandoned conversation', function () {
        Notification::fake();

        $flow = createFlowWithSteps(['customer.first_name', 'customer.email']);
        $conversation = createConversationWithAnswers($flow, ['Partial', 'partial@test.com'], 'abandoned');

        $service = new MessengerLeadCreationService;
        $result = $service->createPartialLead($conversation);

        expect($result['customer'])->toBeInstanceOf(Customer::class);
        expect($result['deal'])->toBeInstanceOf(Deal::class);
        expect($result['deal']->title)->toContain('(Incomplete)');
    });
});

describe('normalizePhone', function () {
    it('strips non-digit characters', function () {
        $service = new MessengerLeadCreationService;

        expect($service->normalizePhone('(555) 123-4567'))->toBe('5551234567');
        expect($service->normalizePhone('555.123.4567'))->toBe('5551234567');
        expect($service->normalizePhone('+1-555-123-4567'))->toBe('15551234567');
        expect($service->normalizePhone('5551234567'))->toBe('5551234567');
    });
});

describe('MessengerHumanTakeoverNeeded notification', function () {
    it('can be instantiated and serialized', function () {
        $conversation = MessengerConversation::factory()->humanTakeover()->create();

        $notification = new MessengerHumanTakeoverNeeded($conversation);

        expect($notification->conversation->id)->toBe($conversation->id);
    });

    it('includes conversation data in array representation', function () {
        $conversation = MessengerConversation::factory()->humanTakeover()->create();
        $user = User::factory()->internal()->create();

        $notification = new MessengerHumanTakeoverNeeded($conversation);
        $data = $notification->toArray($user);

        expect($data['type'])->toBe('messenger_human_takeover');
        expect($data['conversation_id'])->toBe($conversation->id);
        expect($data['thread_id'])->toBe($conversation->thread_id);
    });
});

describe('NewMessengerLead notification', function () {
    it('includes correct data in array representation', function () {
        $customer = Customer::factory()->create(['first_name' => 'Test', 'last_name' => 'Lead']);
        $deal = Deal::factory()->create(['customer_id' => $customer->id, 'title' => 'Test Deal']);
        $user = User::factory()->internal()->create();

        $notification = new NewMessengerLead($customer, $deal, false);
        $data = $notification->toArray($user);

        expect($data['type'])->toBe('new_messenger_lead');
        expect($data['customer_id'])->toBe($customer->id);
        expect($data['deal_id'])->toBe($deal->id);
        expect($data['customer_name'])->toBe('Test Lead');
        expect($data['is_incomplete'])->toBeFalse();
        expect($data['message'])->toContain('New Messenger lead');
    });

    it('shows incomplete message when isIncomplete is true', function () {
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $user = User::factory()->internal()->create();

        $notification = new NewMessengerLead($customer, $deal, true);
        $data = $notification->toArray($user);

        expect($data['is_incomplete'])->toBeTrue();
        expect($data['message'])->toContain('Incomplete');
        expect($data['message'])->toContain('needs follow-up');
    });
});

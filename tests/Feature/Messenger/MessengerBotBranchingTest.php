<?php

use App\Models\ChatThread;
use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Services\MessengerBotFlowService;
use App\Services\MessengerLeadCreationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'verify_token' => 'test-verify-token',
        'app_secret' => 'test-app-secret',
        'page_access_token' => 'test-page-token',
        'page_id' => '123456',
    ]);
    MessengerSetting::clearInstanceCache();

    Http::fake([
        'graph.facebook.com/*' => Http::response(['message_id' => 'mid.test']),
    ]);
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

describe('quick reply branching', function () {
    it('routes to correct step based on quick reply answer', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        // Step 1: Interior or Exterior?
        $step1 = MessengerBotStep::factory()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'step_type' => MessengerBotStep::TYPE_QUICK_REPLY,
            'message_text' => 'Interior or exterior?',
        ]);

        // Step 2: Interior follow-up
        $step2 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'How many rooms?',
        ]);

        // Step 3: Exterior follow-up
        $step3 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 3,
            'message_text' => 'What square footage?',
        ]);

        // Set branching on step 1 options
        $step1->update([
            'quick_reply_options' => [
                ['label' => 'Interior', 'value' => 'interior', 'next_step_id' => $step2->id],
                ['label' => 'Exterior', 'value' => 'exterior', 'next_step_id' => $step3->id],
            ],
        ]);

        $thread = ChatThread::factory()->facebook('branch-1')->create();
        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => 'branch-1',
            'current_step_id' => $step1->id,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);

        // User selects "exterior" → should jump to step 3
        $service->processResponse($conversation, 'exterior');
        $conversation->refresh();

        expect($conversation->current_step_id)->toBe($step3->id);
    });

    it('falls through to sort_order when no branch config exists', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        $step1 = MessengerBotStep::factory()->quickReply([
            ['label' => 'Yes', 'value' => 'yes'],
            ['label' => 'No', 'value' => 'no'],
        ])->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Interested?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'Your name?',
        ]);

        $thread = ChatThread::factory()->facebook('branch-2')->create();
        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => 'branch-2',
            'current_step_id' => $step1->id,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);
        $service->processResponse($conversation, 'yes');
        $conversation->refresh();

        // No branching configured, should advance linearly to step 2
        expect($conversation->current_step_id)->toBe($step2->id);
    });

    it('matches answers case-insensitively', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        $step1 = MessengerBotStep::factory()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'step_type' => MessengerBotStep::TYPE_QUICK_REPLY,
            'message_text' => 'Service type?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'Interior details',
        ]);

        $step3 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 3,
            'message_text' => 'Exterior details',
        ]);

        $step1->update([
            'quick_reply_options' => [
                ['label' => 'Interior', 'value' => 'interior', 'next_step_id' => $step2->id],
                ['label' => 'Exterior', 'value' => 'exterior', 'next_step_id' => $step3->id],
            ],
        ]);

        $thread = ChatThread::factory()->facebook('branch-3')->create();
        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => 'branch-3',
            'current_step_id' => $step1->id,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);

        // User types "INTERIOR" in caps → should still match
        $service->processResponse($conversation, 'INTERIOR');
        $conversation->refresh();

        expect($conversation->current_step_id)->toBe($step2->id);
    });

    it('matches free-text answer against label when value does not match', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        $step1 = MessengerBotStep::factory()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'step_type' => MessengerBotStep::TYPE_QUICK_REPLY,
            'message_text' => 'Service type?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'Interior follow-up',
        ]);

        $step3 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 3,
            'message_text' => 'Exterior follow-up',
        ]);

        $step1->update([
            'quick_reply_options' => [
                ['label' => 'Interior Painting', 'value' => 'int', 'next_step_id' => $step2->id],
                ['label' => 'Exterior Painting', 'value' => 'ext', 'next_step_id' => $step3->id],
            ],
        ]);

        $thread = ChatThread::factory()->facebook('branch-4')->create();
        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => 'branch-4',
            'current_step_id' => $step1->id,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);

        // User types the label text instead of tapping the button
        $service->processResponse($conversation, 'Interior Painting');
        $conversation->refresh();

        expect($conversation->current_step_id)->toBe($step2->id);
    });
});

describe('step next_step_id override', function () {
    it('overrides sort_order to jump to a specific step', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        $step1 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Colors in mind?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'This should be skipped',
        ]);

        $step3 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 3,
            'message_text' => 'Merge point: your name?',
        ]);

        // Step 1 jumps directly to step 3, skipping step 2
        $step1->update(['next_step_id' => $step3->id]);

        $thread = ChatThread::factory()->facebook('jump-1')->create();
        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => 'jump-1',
            'current_step_id' => $step1->id,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);
        $service->processResponse($conversation, 'Blue');
        $conversation->refresh();

        expect($conversation->current_step_id)->toBe($step3->id);
    });

    it('merges two branches at a shared step', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        $step1 = MessengerBotStep::factory()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'step_type' => MessengerBotStep::TYPE_QUICK_REPLY,
            'message_text' => 'Interior or exterior?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'How many rooms?',
        ]);

        $step3 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 3,
            'message_text' => 'Square footage?',
        ]);

        // Merge point
        $step4 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 4,
            'message_text' => 'Your name?',
        ]);

        // Branch from step 1
        $step1->update([
            'quick_reply_options' => [
                ['label' => 'Interior', 'value' => 'interior', 'next_step_id' => $step2->id],
                ['label' => 'Exterior', 'value' => 'exterior', 'next_step_id' => $step3->id],
            ],
        ]);

        // Both step 2 and step 3 merge at step 4
        $step2->update(['next_step_id' => $step4->id]);
        $step3->update(['next_step_id' => $step4->id]);

        $service = app(MessengerBotFlowService::class);

        // Path A: Interior → step 2 → step 4
        $threadA = ChatThread::factory()->facebook('merge-a')->create();
        $convA = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $threadA->id,
            'facebook_psid' => 'merge-a',
            'current_step_id' => $step1->id,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service->processResponse($convA, 'interior');
        $convA->refresh();
        expect($convA->current_step_id)->toBe($step2->id);

        $service->processResponse($convA, '3');
        $convA->refresh();
        expect($convA->current_step_id)->toBe($step4->id);

        // Path B: Exterior → step 3 → step 4
        $threadB = ChatThread::factory()->facebook('merge-b')->create();
        $convB = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $threadB->id,
            'facebook_psid' => 'merge-b',
            'current_step_id' => $step1->id,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service->processResponse($convB, 'exterior');
        $convB->refresh();
        expect($convB->current_step_id)->toBe($step3->id);

        $service->processResponse($convB, '2000');
        $convB->refresh();
        expect($convB->current_step_id)->toBe($step4->id);
    });
});

describe('urgency fast-track', function () {
    it('branches quick reply directly to handoff step', function () {
        Notification::fake();

        $this->mock(MessengerLeadCreationService::class, function ($mock) {
            $mock->shouldReceive('createFromConversation')->once();
        });

        $flow = MessengerBotFlow::factory()->active()->create();

        $step1 = MessengerBotStep::factory()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'step_type' => MessengerBotStep::TYPE_QUICK_REPLY,
            'message_text' => 'When to start?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'Normal path',
        ]);

        $step3 = MessengerBotStep::factory()->handoff()->create([
            'flow_id' => $flow->id,
            'sort_order' => 3,
            'message_text' => 'Connecting you with our team right away!',
        ]);

        $step1->update([
            'quick_reply_options' => [
                ['label' => 'ASAP', 'value' => 'asap', 'next_step_id' => $step3->id],
                ['label' => 'This month', 'value' => 'this_month'],
                ['label' => 'Just exploring', 'value' => 'exploring'],
            ],
        ]);

        $thread = ChatThread::factory()->facebook('urgent-1')->create();
        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => 'urgent-1',
            'current_step_id' => $step1->id,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);
        $service->processResponse($conversation, 'asap');
        $conversation->refresh();

        expect($conversation->current_step_id)->toBe($step3->id);
        expect($conversation->status)->toBe('human_takeover');
    });
});

describe('backward compatibility', function () {
    it('linear flows work identically without any branching config', function () {
        $this->mock(MessengerLeadCreationService::class, function ($mock) {
            $mock->shouldReceive('createFromConversation')->once();
        });

        $flow = MessengerBotFlow::factory()->active()->create();

        $step1 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Name?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion('customer.email')->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'Email?',
        ]);

        $step3 = MessengerBotStep::factory()->textQuestion('customer.phone')->create([
            'flow_id' => $flow->id,
            'sort_order' => 3,
            'message_text' => 'Phone?',
        ]);

        $thread = ChatThread::factory()->facebook('linear-1')->create();
        $service = app(MessengerBotFlowService::class);

        $conversation = $service->startConversation($thread, 'linear-1');
        expect($conversation->current_step_id)->toBe($step1->id);

        $service->processResponse($conversation->fresh(), 'John');
        $conversation->refresh();
        expect($conversation->current_step_id)->toBe($step2->id);

        $service->processResponse($conversation->fresh(), 'john@test.com');
        $conversation->refresh();
        expect($conversation->current_step_id)->toBe($step3->id);

        $service->processResponse($conversation->fresh(), '555-1234');
        $conversation->refresh();
        expect($conversation->status)->toBe('completed');
    });

    it('deleted branch target gracefully falls back to sort_order', function () {
        $flow = MessengerBotFlow::factory()->active()->create();

        $step1 = MessengerBotStep::factory()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'step_type' => MessengerBotStep::TYPE_QUICK_REPLY,
            'message_text' => 'Type?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'Follow-up',
        ]);

        // Create a target step, reference it, then delete it
        $deletedStep = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 99,
            'message_text' => 'Will be deleted',
        ]);

        $step1->update([
            'quick_reply_options' => [
                ['label' => 'Option A', 'value' => 'a', 'next_step_id' => $deletedStep->id],
            ],
        ]);

        // Delete the target step (nullOnDelete cascades next_step_id, but quick_reply_options is JSON)
        $deletedStep->delete();

        $thread = ChatThread::factory()->facebook('deleted-1')->create();
        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => 'deleted-1',
            'current_step_id' => $step1->id,
            'status' => 'in_progress',
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        $service = app(MessengerBotFlowService::class);
        $service->processResponse($conversation, 'a');
        $conversation->refresh();

        // Target was deleted, should fall through to linear next step (step2)
        expect($conversation->current_step_id)->toBe($step2->id);
    });
});

describe('full end-to-end branching flow', function () {
    it('branches, diverges, merges, and completes', function () {
        $this->mock(MessengerLeadCreationService::class, function ($mock) {
            $mock->shouldReceive('createFromConversation')->once();
        });

        $flow = MessengerBotFlow::factory()->active()->create([
            'greeting_message' => 'Welcome!',
        ]);

        // Step 1: Branch point
        $step1 = MessengerBotStep::factory()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'step_type' => MessengerBotStep::TYPE_QUICK_REPLY,
            'message_text' => 'Interior or exterior?',
        ]);

        // Step 2: Interior path
        $step2 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'How many rooms?',
        ]);

        // Step 3: Exterior path
        $step3 = MessengerBotStep::factory()->textQuestion()->create([
            'flow_id' => $flow->id,
            'sort_order' => 3,
            'message_text' => 'Square footage?',
        ]);

        // Step 4: Merge point
        $step4 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 4,
            'message_text' => 'Your name?',
        ]);

        // Step 5: Final
        $step5 = MessengerBotStep::factory()->textQuestion('customer.phone')->create([
            'flow_id' => $flow->id,
            'sort_order' => 5,
            'message_text' => 'Phone number?',
        ]);

        // Configure branching
        $step1->update([
            'quick_reply_options' => [
                ['label' => 'Interior', 'value' => 'interior', 'next_step_id' => $step2->id],
                ['label' => 'Exterior', 'value' => 'exterior', 'next_step_id' => $step3->id],
            ],
        ]);

        // Both paths merge at step 4
        $step2->update(['next_step_id' => $step4->id]);
        $step3->update(['next_step_id' => $step4->id]);

        $thread = ChatThread::factory()->facebook('e2e-1')->create();
        $service = app(MessengerBotFlowService::class);

        // Start conversation
        $conversation = $service->startConversation($thread, 'e2e-1');
        expect($conversation->current_step_id)->toBe($step1->id);

        // Branch: choose interior → goes to step 2
        $service->processResponse($conversation->fresh(), 'interior');
        $conversation->refresh();
        expect($conversation->current_step_id)->toBe($step2->id);

        // Answer rooms → merges to step 4 (skipping step 3)
        $service->processResponse($conversation->fresh(), '3 rooms');
        $conversation->refresh();
        expect($conversation->current_step_id)->toBe($step4->id);

        // Answer name → linear to step 5
        $service->processResponse($conversation->fresh(), 'John');
        $conversation->refresh();
        expect($conversation->current_step_id)->toBe($step5->id);

        // Answer phone → completes
        $service->processResponse($conversation->fresh(), '555-1234');
        $conversation->refresh();
        expect($conversation->status)->toBe('completed');
    });
});

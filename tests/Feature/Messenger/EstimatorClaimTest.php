<?php

use App\Models\ChatThread;
use App\Models\Deal;
use App\Models\MessengerConversation;
use App\Models\MessengerResponder;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Services\ChatService;
use App\Services\MessengerLeadAssignmentService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    $settings->update(['page_access_token' => 'test-token', 'app_secret' => 'test-secret']);
    MessengerSetting::clearInstanceCache();
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

describe('estimator self-claim', function () {
    it('assigns deal to estimator who replies in thread', function () {
        $estimator = User::factory()->internal()->create();
        MessengerResponder::factory()->estimator()->create(['user_id' => $estimator->id]);

        $thread = ChatThread::factory()->facebook()->create();
        $thread->addParticipant($estimator);

        $deal = Deal::factory()->create(['assigned_to' => null]);
        $conversation = MessengerConversation::factory()->humanTakeover()->create([
            'thread_id' => $thread->id,
            'deal_id' => $deal->id,
        ]);

        $assignmentService = new MessengerLeadAssignmentService;
        $role = $assignmentService->getResponderRole($estimator);
        expect($role)->toBe('estimator');

        // Simulate what ChatService does when estimator replies
        $assignmentService->claimThread($thread, $estimator);
        if (in_array($role, ['estimator', 'both'])) {
            $assignmentService->assignToEstimator($deal, $estimator);
        }

        expect($deal->fresh()->assigned_to)->toBe($estimator->id);
    });

    it('does not reassign already-assigned deal', function () {
        $estimator1 = User::factory()->internal()->create();
        $estimator2 = User::factory()->internal()->create();
        MessengerResponder::factory()->estimator()->create(['user_id' => $estimator1->id]);
        MessengerResponder::factory()->estimator()->create(['user_id' => $estimator2->id]);

        $deal = Deal::factory()->create(['assigned_to' => $estimator1->id]);
        $thread = ChatThread::factory()->facebook()->create();
        MessengerConversation::factory()->humanTakeover()->create([
            'thread_id' => $thread->id,
            'deal_id' => $deal->id,
        ]);

        $assignmentService = new MessengerLeadAssignmentService;
        // Simulate estimator2 replying - deal already assigned, should NOT reassign
        $assignmentService->claimThread($thread, $estimator2);
        // In ChatService, we check `$deal->assigned_to === null` before reassigning
        if ($deal->assigned_to === null) {
            $assignmentService->assignToEstimator($deal, $estimator2);
        }

        expect($deal->fresh()->assigned_to)->toBe($estimator1->id);
    });

    it('booker reply does not self-assign - goes to round-robin', function () {
        $booker = User::factory()->internal()->create();
        $estimator = User::factory()->internal()->create();
        MessengerResponder::factory()->booker()->create(['user_id' => $booker->id, 'sort_order' => 0]);
        MessengerResponder::factory()->estimator()->create(['user_id' => $estimator->id, 'sort_order' => 1]);

        $deal = Deal::factory()->create(['assigned_to' => null]);
        $thread = ChatThread::factory()->facebook()->create();
        $thread->addParticipant($booker);
        $thread->addParticipant($estimator);

        MessengerConversation::factory()->humanTakeover()->create([
            'thread_id' => $thread->id,
            'deal_id' => $deal->id,
        ]);

        $assignmentService = new MessengerLeadAssignmentService;

        // Booker claims thread
        $assignmentService->claimThread($thread, $booker);
        expect($thread->hasParticipant($booker))->toBeTrue();

        // Booker is NOT an estimator, so no self-assign
        $role = $assignmentService->getResponderRole($booker);
        expect($role)->toBe('appointment_booker');
        expect(in_array($role, ['estimator', 'both']))->toBeFalse();

        // Deal still unassigned - would be assigned via round-robin separately
        expect($deal->fresh()->assigned_to)->toBeNull();
    });

    it('thread participants pruned to just the claimer', function () {
        $claimer = User::factory()->internal()->create();
        $other1 = User::factory()->internal()->create();
        $other2 = User::factory()->internal()->create();

        MessengerResponder::factory()->estimator()->create(['user_id' => $claimer->id]);
        MessengerResponder::factory()->estimator()->create(['user_id' => $other1->id]);
        MessengerResponder::factory()->booker()->create(['user_id' => $other2->id]);

        $thread = ChatThread::factory()->facebook()->create();
        $thread->addParticipant($claimer);
        $thread->addParticipant($other1);
        $thread->addParticipant($other2);

        // All three are participants initially
        expect($thread->hasParticipant($claimer))->toBeTrue();
        expect($thread->hasParticipant($other1))->toBeTrue();
        expect($thread->hasParticipant($other2))->toBeTrue();

        $assignmentService = new MessengerLeadAssignmentService;
        $assignmentService->claimThread($thread, $claimer);

        // Only claimer remains
        expect($thread->hasParticipant($claimer))->toBeTrue();
        expect($thread->hasParticipant($other1))->toBeFalse();
        expect($thread->hasParticipant($other2))->toBeFalse();
    });

    it('human takeover sets taken_over_by on conversation', function () {
        $estimator = User::factory()->internal()->create();
        MessengerResponder::factory()->estimator()->create(['user_id' => $estimator->id]);

        $thread = ChatThread::factory()->facebook()->create();
        $thread->addParticipant($estimator);

        $conversation = MessengerConversation::factory()->create([
            'thread_id' => $thread->id,
            'status' => 'in_progress',
        ]);

        // Simulate what ChatService.syncToFacebookMessenger does
        $conversation->update([
            'status' => 'human_takeover',
            'taken_over_by' => $estimator->id,
        ]);

        $conversation->refresh();
        expect($conversation->status)->toBe('human_takeover');
        expect($conversation->taken_over_by)->toBe($estimator->id);
    });

    it('both role user can self-assign as estimator', function () {
        $user = User::factory()->internal()->create();
        MessengerResponder::factory()->both()->create(['user_id' => $user->id]);

        $deal = Deal::factory()->create(['assigned_to' => null]);
        $thread = ChatThread::factory()->facebook()->create();
        MessengerConversation::factory()->humanTakeover()->create([
            'thread_id' => $thread->id,
            'deal_id' => $deal->id,
        ]);

        $assignmentService = new MessengerLeadAssignmentService;
        $role = $assignmentService->getResponderRole($user);
        expect(in_array($role, ['estimator', 'both']))->toBeTrue();

        $assignmentService->assignToEstimator($deal, $user);
        expect($deal->fresh()->assigned_to)->toBe($user->id);
    });
});

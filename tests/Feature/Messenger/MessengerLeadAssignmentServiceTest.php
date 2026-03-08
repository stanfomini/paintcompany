<?php

use App\Models\ChatThread;
use App\Models\Deal;
use App\Models\MessengerResponder;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Services\MessengerLeadAssignmentService;

beforeEach(function () {
    MessengerSetting::clearInstanceCache();
    $this->service = new MessengerLeadAssignmentService;
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

describe('getNextEstimator', function () {
    it('returns null when no estimators exist', function () {
        expect($this->service->getNextEstimator())->toBeNull();
    });

    it('returns the only estimator when there is one', function () {
        $responder = MessengerResponder::factory()->estimator()->create();

        $estimator = $this->service->getNextEstimator();

        expect($estimator)->not->toBeNull();
        expect($estimator->id)->toBe($responder->user_id);
    });

    it('assigns estimators in round-robin sequence', function () {
        $r1 = MessengerResponder::factory()->estimator()->create(['sort_order' => 0]);
        $r2 = MessengerResponder::factory()->estimator()->create(['sort_order' => 1]);
        $r3 = MessengerResponder::factory()->estimator()->create(['sort_order' => 2]);

        // First call: r1 (null last_assigned_at, lowest sort_order)
        $first = $this->service->getNextEstimator();
        expect($first->id)->toBe($r1->user_id);

        // Second call: r2 (null last_assigned_at, next sort_order)
        $second = $this->service->getNextEstimator();
        expect($second->id)->toBe($r2->user_id);

        // Third call: r3
        $third = $this->service->getNextEstimator();
        expect($third->id)->toBe($r3->user_id);

        // Fourth call: cycles back to r1 (oldest last_assigned_at)
        $fourth = $this->service->getNextEstimator();
        expect($fourth->id)->toBe($r1->user_id);
    });

    it('skips inactive responders', function () {
        MessengerResponder::factory()->estimator()->inactive()->create(['sort_order' => 0]);
        $active = MessengerResponder::factory()->estimator()->create(['sort_order' => 1]);

        $estimator = $this->service->getNextEstimator();

        expect($estimator->id)->toBe($active->user_id);
    });

    it('skips appointment_booker role in estimator round-robin', function () {
        MessengerResponder::factory()->booker()->create(['sort_order' => 0]);
        $estimator = MessengerResponder::factory()->estimator()->create(['sort_order' => 1]);

        $result = $this->service->getNextEstimator();

        expect($result->id)->toBe($estimator->user_id);
    });

    it('includes "both" role in estimator round-robin', function () {
        $both = MessengerResponder::factory()->both()->create(['sort_order' => 0]);

        $result = $this->service->getNextEstimator();

        expect($result->id)->toBe($both->user_id);
    });

    it('updates last_assigned_at after assignment', function () {
        $responder = MessengerResponder::factory()->estimator()->create();

        expect($responder->last_assigned_at)->toBeNull();

        $this->service->getNextEstimator();

        expect($responder->fresh()->last_assigned_at)->not->toBeNull();
    });
});

describe('assignToEstimator', function () {
    it('assigns deal to specific estimator', function () {
        $deal = Deal::factory()->create(['assigned_to' => null]);
        $user = User::factory()->internal()->create();
        MessengerResponder::factory()->estimator()->create(['user_id' => $user->id]);

        $this->service->assignToEstimator($deal, $user);

        expect($deal->fresh()->assigned_to)->toBe($user->id);
    });

    it('updates last_assigned_at for the estimator', function () {
        $deal = Deal::factory()->create(['assigned_to' => null]);
        $user = User::factory()->internal()->create();
        $responder = MessengerResponder::factory()->estimator()->create(['user_id' => $user->id]);

        $this->service->assignToEstimator($deal, $user);

        expect($responder->fresh()->last_assigned_at)->not->toBeNull();
    });
});

describe('assignViaRoundRobin', function () {
    it('assigns deal to next estimator via round-robin', function () {
        $deal = Deal::factory()->create(['assigned_to' => null]);
        $responder = MessengerResponder::factory()->estimator()->create();

        $assignedUser = $this->service->assignViaRoundRobin($deal);

        expect($assignedUser)->not->toBeNull();
        expect($assignedUser->id)->toBe($responder->user_id);
        expect($deal->fresh()->assigned_to)->toBe($responder->user_id);
    });

    it('falls back to default_assigned_to when roster is empty', function () {
        $fallbackUser = User::factory()->internal()->create();
        $settings = MessengerSetting::getInstance();
        $settings->update(['default_assigned_to' => $fallbackUser->id]);
        MessengerSetting::clearInstanceCache();

        $deal = Deal::factory()->create(['assigned_to' => null]);

        $assignedUser = $this->service->assignViaRoundRobin($deal);

        expect($assignedUser)->not->toBeNull();
        expect($assignedUser->id)->toBe($fallbackUser->id);
        expect($deal->fresh()->assigned_to)->toBe($fallbackUser->id);
    });

    it('returns null when no estimators and no fallback', function () {
        $settings = MessengerSetting::getInstance();
        $settings->update(['default_assigned_to' => null]);
        MessengerSetting::clearInstanceCache();

        $deal = Deal::factory()->create(['assigned_to' => null]);

        expect($this->service->assignViaRoundRobin($deal))->toBeNull();
    });
});

describe('getResponderRole', function () {
    it('returns role for active responder', function () {
        $user = User::factory()->internal()->create();
        MessengerResponder::factory()->estimator()->create(['user_id' => $user->id]);

        expect($this->service->getResponderRole($user))->toBe('estimator');
    });

    it('returns null for non-responder', function () {
        $user = User::factory()->internal()->create();

        expect($this->service->getResponderRole($user))->toBeNull();
    });

    it('returns null for inactive responder', function () {
        $user = User::factory()->internal()->create();
        MessengerResponder::factory()->estimator()->inactive()->create(['user_id' => $user->id]);

        expect($this->service->getResponderRole($user))->toBeNull();
    });
});

describe('isActiveResponder', function () {
    it('returns true for active responder', function () {
        $user = User::factory()->internal()->create();
        MessengerResponder::factory()->estimator()->create(['user_id' => $user->id]);

        expect($this->service->isActiveResponder($user))->toBeTrue();
    });

    it('returns false for inactive responder', function () {
        $user = User::factory()->internal()->create();
        MessengerResponder::factory()->estimator()->inactive()->create(['user_id' => $user->id]);

        expect($this->service->isActiveResponder($user))->toBeFalse();
    });

    it('returns false for non-responder', function () {
        $user = User::factory()->internal()->create();

        expect($this->service->isActiveResponder($user))->toBeFalse();
    });
});

describe('getCreatedByUser', function () {
    it('returns first active responder', function () {
        $r1 = MessengerResponder::factory()->estimator()->create(['sort_order' => 0]);
        MessengerResponder::factory()->estimator()->create(['sort_order' => 1]);

        $user = $this->service->getCreatedByUser();

        expect($user->id)->toBe($r1->user_id);
    });

    it('falls back to default_assigned_to when roster empty', function () {
        $fallbackUser = User::factory()->internal()->create();
        $settings = MessengerSetting::getInstance();
        $settings->update(['default_assigned_to' => $fallbackUser->id]);
        MessengerSetting::clearInstanceCache();

        $user = $this->service->getCreatedByUser();

        expect($user->id)->toBe($fallbackUser->id);
    });

    it('falls back to first internal user as last resort', function () {
        $settings = MessengerSetting::getInstance();
        $settings->update(['default_assigned_to' => null]);
        MessengerSetting::clearInstanceCache();

        $internalUser = User::factory()->internal()->create();

        $user = $this->service->getCreatedByUser();

        expect($user->id)->toBe($internalUser->id);
    });
});

describe('claimThread', function () {
    it('removes other responder participants from thread', function () {
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

        $this->service->claimThread($thread, $claimer);

        expect($thread->hasParticipant($claimer))->toBeTrue();
        expect($thread->hasParticipant($other1))->toBeFalse();
        expect($thread->hasParticipant($other2))->toBeFalse();
    });

    it('does not remove non-responder participants', function () {
        $claimer = User::factory()->internal()->create();
        $nonResponder = User::factory()->internal()->create();

        MessengerResponder::factory()->estimator()->create(['user_id' => $claimer->id]);

        $thread = ChatThread::factory()->facebook()->create();
        $thread->addParticipant($claimer);
        $thread->addParticipant($nonResponder);

        $this->service->claimThread($thread, $claimer);

        expect($thread->hasParticipant($claimer))->toBeTrue();
        expect($thread->hasParticipant($nonResponder))->toBeTrue();
    });
});

<?php

use App\Models\ChatMessage;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;

describe('Estimate Welcome Message', function () {
    it('sends a welcome system message when the first estimate is created for a deal', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $internalUser = User::factory()->internal()->create();

        $estimate = Estimate::factory()->forDeal($deal)->create(['created_by' => $internalUser->id]);

        $thread = $deal->fresh()->chatThread;
        expect($thread)->not->toBeNull();

        $systemMessage = $thread->messages()->where('type', ChatMessage::TYPE_SYSTEM)->first();
        expect($systemMessage)->not->toBeNull();
        expect($systemMessage->content)->toContain('Welcome to your painting project');
    });

    it('does not send welcome message for second estimate on same deal', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $internalUser = User::factory()->internal()->create();

        Estimate::factory()->forDeal($deal)->create(['created_by' => $internalUser->id]);

        $systemMessageCount = $deal->fresh()->chatThread->messages()
            ->where('type', ChatMessage::TYPE_SYSTEM)->count();

        Estimate::factory()->forDeal($deal)->create(['created_by' => $internalUser->id]);

        $newCount = $deal->fresh()->chatThread->messages()
            ->where('type', ChatMessage::TYPE_SYSTEM)->count();

        expect($newCount)->toBe($systemMessageCount);
    });

    it('creates chat thread if one does not exist', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $internalUser = User::factory()->internal()->create();

        expect($deal->chatThread)->toBeNull();

        Estimate::factory()->forDeal($deal)->create(['created_by' => $internalUser->id]);

        expect($deal->fresh()->chatThread)->not->toBeNull();
    });
});

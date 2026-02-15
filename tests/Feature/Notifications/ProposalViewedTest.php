<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateView;
use App\Models\User;
use App\Notifications\ProposalViewed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Cache::flush();
});

it('sends notification on first proposal view', function () {
    $assignee = User::factory()->internal()->create();
    $customer = Customer::factory()->assignedTo($assignee)->create();
    $deal = Deal::factory()->forCustomer($customer)->create();
    $estimate = Estimate::factory()->forDeal($deal)->create();

    EstimateView::record($estimate);

    Notification::assertSentTo($assignee, ProposalViewed::class);
});

it('throttles notification for same estimate within 1 hour', function () {
    $assignee = User::factory()->internal()->create();
    $customer = Customer::factory()->assignedTo($assignee)->create();
    $deal = Deal::factory()->forCustomer($customer)->create();
    $estimate = Estimate::factory()->forDeal($deal)->create();

    EstimateView::record($estimate);
    EstimateView::record($estimate);
    EstimateView::record($estimate);

    Notification::assertSentToTimes($assignee, ProposalViewed::class, 1);
});

it('re-sends notification after cache expires', function () {
    $assignee = User::factory()->internal()->create();
    $customer = Customer::factory()->assignedTo($assignee)->create();
    $deal = Deal::factory()->forCustomer($customer)->create();
    $estimate = Estimate::factory()->forDeal($deal)->create();

    EstimateView::record($estimate);
    Notification::assertSentToTimes($assignee, ProposalViewed::class, 1);

    Cache::forget("proposal_viewed_notify:{$estimate->id}");

    EstimateView::record($estimate);
    Notification::assertSentToTimes($assignee, ProposalViewed::class, 2);
});

it('resolves assignee from deal first', function () {
    $dealAssignee = User::factory()->internal()->create();
    $customerAssignee = User::factory()->internal()->create();

    $customer = Customer::factory()->assignedTo($customerAssignee)->create();
    $deal = Deal::factory()->forCustomer($customer)->assignedTo($dealAssignee)->create();
    $estimate = Estimate::factory()->forDeal($deal)->create();

    EstimateView::record($estimate);

    Notification::assertSentTo($dealAssignee, ProposalViewed::class);
    Notification::assertNotSentTo($customerAssignee, ProposalViewed::class);
});

it('falls back to customer assignee when deal has no assignee', function () {
    $customerAssignee = User::factory()->internal()->create();
    $customer = Customer::factory()->assignedTo($customerAssignee)->create();
    $deal = Deal::factory()->forCustomer($customer)->create(['assigned_to' => null]);
    $estimate = Estimate::factory()->forDeal($deal)->create();

    EstimateView::record($estimate);

    Notification::assertSentTo($customerAssignee, ProposalViewed::class);
});

it('does not send notification when no assignee exists', function () {
    $customer = Customer::factory()->create(['assigned_to' => null]);
    $deal = Deal::factory()->forCustomer($customer)->create(['assigned_to' => null]);
    $estimate = Estimate::factory()->forDeal($deal)->create();

    EstimateView::record($estimate);

    Notification::assertNothingSent();
});

it('includes correct data in notification', function () {
    $assignee = User::factory()->internal()->create();
    $customer = Customer::factory()->assignedTo($assignee)->create();
    $deal = Deal::factory()->forCustomer($customer)->create();
    $estimate = Estimate::factory()->forDeal($deal)->create();

    EstimateView::record($estimate);

    Notification::assertSentTo($assignee, ProposalViewed::class, function ($notification) use ($estimate, $assignee) {
        $data = $notification->toArray($assignee);

        return $data['estimate_id'] === $estimate->id
            && $data['estimate_number'] === $estimate->estimate_number
            && $data['type'] === 'proposal_viewed';
    });
});

it('uses mail database and broadcast channels', function () {
    $notification = new ProposalViewed(Estimate::factory()->create());

    expect($notification->via(new stdClass))->toBe(['mail', 'database', 'broadcast']);
});

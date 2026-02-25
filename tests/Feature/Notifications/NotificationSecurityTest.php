<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateView;
use App\Models\Job;
use App\Models\User;
use App\Notifications\CrewJobDispatched;
use App\Notifications\CustomerConfirmedColors;
use App\Notifications\CustomerConfirmedDate;
use App\Notifications\CustomerPortalLogin;
use App\Notifications\CustomerProposedDate;
use App\Notifications\EstimateReadyForAgent;
use App\Notifications\JobStageAdvanced;
use App\Notifications\MessengerHumanTakeoverNeeded;
use App\Notifications\NewAccountCreated;
use App\Notifications\NewMessengerLead;
use App\Notifications\ProposalViewed;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    (new RolesAndPermissionsSeeder)->run();
    Notification::fake();
});

describe('Internal-only notifications block non-internal users', function () {
    it('ProposalViewed returns empty channels for customer users', function () {
        $customer = User::factory()->customer()->create();
        $estimate = Estimate::factory()->create();

        $notification = new ProposalViewed($estimate);

        expect($notification->via($customer))->toBe([]);
    });

    it('ProposalViewed returns channels for internal users', function () {
        $internal = User::factory()->internal()->create();
        $estimate = Estimate::factory()->create();

        $notification = new ProposalViewed($estimate);

        expect($notification->via($internal))->not->toBe([]);
    });

    it('CustomerPortalLogin returns empty channels for customer users', function () {
        $customer = User::factory()->customer()->create();
        $loginUser = User::factory()->customer()->create();

        $notification = new CustomerPortalLogin($loginUser);

        expect($notification->via($customer))->toBe([]);
    });

    it('CustomerPortalLogin returns channels for internal users', function () {
        $internal = User::factory()->internal()->create();
        $loginUser = User::factory()->customer()->create();

        $notification = new CustomerPortalLogin($loginUser);

        expect($notification->via($internal))->not->toBe([]);
    });

    it('JobStageAdvanced returns empty channels for customer users', function () {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->create();

        $notification = new JobStageAdvanced($job, 'estimate_accepted', 'scheduling_colors');

        expect($notification->via($customer))->toBe([]);
    });

    it('CustomerConfirmedDate returns empty channels for customer users', function () {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->create();

        $notification = new CustomerConfirmedDate($job);

        expect($notification->via($customer))->toBe([]);
    });

    it('CustomerConfirmedColors returns empty channels for customer users', function () {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->create();

        $notification = new CustomerConfirmedColors($job);

        expect($notification->via($customer))->toBe([]);
    });

    it('CustomerProposedDate returns empty channels for customer users', function () {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->create();

        $notification = new CustomerProposedDate($job, 'Monday, January 1, 2026');

        expect($notification->via($customer))->toBe([]);
    });

    it('NewMessengerLead returns empty channels for customer users', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        $notification = new NewMessengerLead($customer, $deal);

        expect($notification->via($customerUser))->toBe([]);
    });

    it('MessengerHumanTakeoverNeeded returns empty channels for customer users', function () {
        $customer = User::factory()->customer()->create();
        $conversation = \App\Models\MessengerConversation::factory()->create();

        $notification = new MessengerHumanTakeoverNeeded($conversation);

        expect($notification->via($customer))->toBe([]);
    });

    it('NewAccountCreated returns empty channels for customer users', function () {
        $customer = User::factory()->customer()->create();
        $newUser = User::factory()->customer()->create();

        $notification = new NewAccountCreated($newUser);

        expect($notification->via($customer))->toBe([]);
    });
});

describe('Role-specific notifications block wrong user types', function () {
    it('CrewJobDispatched returns empty channels for customer users', function () {
        $customer = User::factory()->customer()->create();
        $job = Job::factory()->create();

        $notification = new CrewJobDispatched($job);

        expect($notification->via($customer))->toBe([]);
    });

    it('CrewJobDispatched returns channels for crew users', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();

        $notification = new CrewJobDispatched($job);

        expect($notification->via($crew))->not->toBe([]);
    });

    it('EstimateReadyForAgent returns empty channels for customer users', function () {
        $customer = User::factory()->customer()->create();
        $estimate = Estimate::factory()->create();
        $deal = Deal::factory()->create();

        $notification = new EstimateReadyForAgent($estimate, $deal);

        expect($notification->via($customer))->toBe([]);
    });

    it('EstimateReadyForAgent returns channels for agent users', function () {
        $agent = User::factory()->agent()->create();
        $estimate = Estimate::factory()->create();
        $deal = Deal::factory()->create();

        $notification = new EstimateReadyForAgent($estimate, $deal);

        expect($notification->via($agent))->not->toBe([]);
    });
});

describe('Dispatch site guards prevent wrong user types', function () {
    it('EstimateView does not notify customer assignees about proposal views', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id, 'assigned_to' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id, 'assigned_to' => $customerUser->id]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        EstimateView::record($estimate);

        Notification::assertNothingSent();
    });

    it('EstimateView notifies internal assignees about proposal views', function () {
        $internalUser = User::factory()->internal()->create();
        $customer = Customer::factory()->create(['assigned_to' => $internalUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id, 'assigned_to' => $internalUser->id]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        EstimateView::record($estimate);

        Notification::assertSentTo($internalUser, ProposalViewed::class);
    });
});

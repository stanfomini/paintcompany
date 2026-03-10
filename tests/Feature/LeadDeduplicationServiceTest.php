<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\MessengerConversation;
use App\Models\User;
use App\Services\LeadDeduplicationService;

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->withFullAccess()->create();
    $this->service = new LeadDeduplicationService;
});

// ──────────────────────────────────────────────────
// findExistingCustomer
// ──────────────────────────────────────────────────

describe('findExistingCustomer', function () {
    it('returns null when no match exists', function () {
        $result = $this->service->findExistingCustomer([
            'email' => 'nobody@example.com',
            'phone' => '555-000-0000',
        ]);

        expect($result)->toBeNull();
    });

    it('finds customer by email (case-insensitive)', function () {
        $customer = Customer::factory()->create(['email' => 'Jane@Example.com']);

        $result = $this->service->findExistingCustomer(['email' => 'jane@example.com']);

        expect($result)->not->toBeNull();
        expect($result->id)->toBe($customer->id);
    });

    it('finds customer by phone (normalized)', function () {
        $customer = Customer::factory()->create(['phone' => '(303) 555-1234']);

        $result = $this->service->findExistingCustomer([
            'email' => null,
            'phone' => '303-555-1234',
        ]);

        expect($result)->not->toBeNull();
        expect($result->id)->toBe($customer->id);
    });

    it('finds customer by PSID via messenger conversation', function () {
        $customer = Customer::factory()->create();
        MessengerConversation::factory()->create([
            'facebook_psid' => 'psid_123',
            'customer_id' => $customer->id,
        ]);

        $result = $this->service->findExistingCustomer([], 'psid_123');

        expect($result)->not->toBeNull();
        expect($result->id)->toBe($customer->id);
    });

    it('prioritizes PSID over email', function () {
        $psidCustomer = Customer::factory()->create(['email' => 'psid@test.com']);
        $emailCustomer = Customer::factory()->create(['email' => 'email@test.com']);
        MessengerConversation::factory()->create([
            'facebook_psid' => 'psid_priority',
            'customer_id' => $psidCustomer->id,
        ]);

        $result = $this->service->findExistingCustomer(
            ['email' => 'email@test.com'],
            'psid_priority'
        );

        expect($result->id)->toBe($psidCustomer->id);
    });

    it('ignores short phone numbers', function () {
        Customer::factory()->create(['phone' => '123']);

        $result = $this->service->findExistingCustomer([
            'email' => null,
            'phone' => '123',
        ]);

        expect($result)->toBeNull();
    });
});

// ──────────────────────────────────────────────────
// findActiveDeal
// ──────────────────────────────────────────────────

describe('findActiveDeal', function () {
    it('returns active deal for customer', function () {
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->lead()->create();

        $result = $this->service->findActiveDeal($customer);

        expect($result)->not->toBeNull();
        expect($result->id)->toBe($deal->id);
    });

    it('returns null when all deals are terminal', function () {
        $customer = Customer::factory()->create();
        Deal::factory()->forCustomer($customer)->signed()->create();
        Deal::factory()->forCustomer($customer)->completed()->create();
        Deal::factory()->forCustomer($customer)->lost()->create();
        Deal::factory()->forCustomer($customer)->cancelled()->create();

        $result = $this->service->findActiveDeal($customer);

        expect($result)->toBeNull();
    });

    it('returns latest active deal when multiple exist', function () {
        $customer = Customer::factory()->create();
        $older = Deal::factory()->forCustomer($customer)->lead()->create(['created_at' => now()->subDay()]);
        $newer = Deal::factory()->forCustomer($customer)->quoted()->create(['created_at' => now()]);

        $result = $this->service->findActiveDeal($customer);

        expect($result->id)->toBe($newer->id);
    });

    it('returns null when customer has no deals', function () {
        $customer = Customer::factory()->create();

        $result = $this->service->findActiveDeal($customer);

        expect($result)->toBeNull();
    });

    it('finds deals across all pre-sale stages', function () {
        $customer = Customer::factory()->create();

        foreach (['lead', 'not_contacted', 'follow_up', 'quote_scheduled', 'quoted'] as $stage) {
            // Clean up previous deals
            Deal::where('customer_id', $customer->id)->delete();

            $deal = Deal::factory()->forCustomer($customer)->stage($stage)->create();
            $result = $this->service->findActiveDeal($customer);

            expect($result)->not->toBeNull("Expected to find deal in stage: {$stage}");
            expect($result->id)->toBe($deal->id);
        }
    });
});

// ──────────────────────────────────────────────────
// resolveOrCreate
// ──────────────────────────────────────────────────

describe('resolveOrCreate', function () {
    it('creates new customer and deal when no match exists', function () {
        $result = $this->service->resolveOrCreate(
            customerData: [
                'first_name' => 'New',
                'last_name' => 'Person',
                'email' => 'new@example.com',
                'source' => 'online',
                'status' => 'active',
                'created_by' => $this->internalUser->id,
            ],
            dealData: [
                'title' => 'Test Deal',
                'stage' => 'lead',
                'lead_source' => 'website',
                'created_by' => $this->internalUser->id,
            ],
        );

        expect($result->customerWasExisting)->toBeFalse();
        expect($result->dealWasExisting)->toBeFalse();
        expect($result->customer->email)->toBe('new@example.com');
        expect($result->deal->title)->toBe('Test Deal');
    });

    it('reuses existing customer and active deal', function () {
        $customer = Customer::factory()->create(['email' => 'existing@example.com']);
        $deal = Deal::factory()->forCustomer($customer)->lead()->create();

        $result = $this->service->resolveOrCreate(
            customerData: ['email' => 'existing@example.com', 'first_name' => 'Test', 'last_name' => 'User', 'status' => 'active', 'created_by' => $this->internalUser->id],
            dealData: ['title' => 'Should Not Create', 'stage' => 'lead', 'lead_source' => 'website', 'created_by' => $this->internalUser->id],
            options: ['source_label' => 'Website'],
        );

        expect($result->customerWasExisting)->toBeTrue();
        expect($result->dealWasExisting)->toBeTrue();
        expect($result->deal->id)->toBe($deal->id);

        // Verify only 1 deal exists for this customer
        expect(Deal::where('customer_id', $customer->id)->count())->toBe(1);
    });

    it('creates new deal when existing customer has only terminal deals', function () {
        $customer = Customer::factory()->create(['email' => 'done@example.com']);
        Deal::factory()->forCustomer($customer)->completed()->create();

        $result = $this->service->resolveOrCreate(
            customerData: ['email' => 'done@example.com', 'first_name' => 'Done', 'last_name' => 'User', 'status' => 'active', 'created_by' => $this->internalUser->id],
            dealData: ['title' => 'New Project', 'stage' => 'lead', 'lead_source' => 'website', 'created_by' => $this->internalUser->id],
        );

        expect($result->customerWasExisting)->toBeTrue();
        expect($result->dealWasExisting)->toBeFalse();
        expect($result->deal->title)->toBe('New Project');
        expect(Deal::where('customer_id', $customer->id)->count())->toBe(2);
    });

    it('creates estimate when requested', function () {
        $result = $this->service->resolveOrCreate(
            customerData: ['first_name' => 'Est', 'last_name' => 'Test', 'email' => 'est@test.com', 'status' => 'active', 'created_by' => $this->internalUser->id],
            dealData: ['title' => 'With Estimate', 'stage' => 'lead', 'lead_source' => 'website', 'created_by' => $this->internalUser->id],
            options: ['create_estimate' => true],
        );

        expect($result->estimate)->not->toBeNull();
        expect($result->estimate->deal_id)->toBe($result->deal->id);
        expect($result->estimateWasExisting)->toBeFalse();
    });

    it('returns existing estimate when deal is reused', function () {
        $customer = Customer::factory()->create(['email' => 'est-reuse@test.com']);
        $deal = Deal::factory()->forCustomer($customer)->lead()->create();
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        $result = $this->service->resolveOrCreate(
            customerData: ['email' => 'est-reuse@test.com', 'first_name' => 'Test', 'last_name' => 'User', 'status' => 'active', 'created_by' => $this->internalUser->id],
            dealData: ['title' => 'Ignored', 'stage' => 'lead', 'lead_source' => 'website', 'created_by' => $this->internalUser->id],
            options: ['source_label' => 'Test'],
        );

        expect($result->estimateWasExisting)->toBeTrue();
        expect($result->estimate->id)->toBe($estimate->id);
    });

    it('fills missing customer fields without overwriting existing ones', function () {
        $customer = Customer::factory()->create([
            'email' => 'partial@test.com',
            'phone' => '303-555-9999',
            'address' => null,
            'city' => null,
        ]);
        Deal::factory()->forCustomer($customer)->lead()->create();

        $this->service->resolveOrCreate(
            customerData: [
                'email' => 'partial@test.com',
                'phone' => '111-222-3333', // Should NOT overwrite existing
                'address' => '123 New St', // Should fill
                'city' => 'Denver', // Should fill
                'first_name' => 'Test',
                'last_name' => 'User',
                'status' => 'active',
                'created_by' => $this->internalUser->id,
            ],
            dealData: ['title' => 'Test', 'stage' => 'lead', 'lead_source' => 'website', 'created_by' => $this->internalUser->id],
            options: ['source_label' => 'Test'],
        );

        $customer->refresh();
        expect($customer->phone)->toBe('303-555-9999'); // Not overwritten
        expect($customer->address)->toBe('123 New St'); // Filled
        expect($customer->city)->toBe('Denver'); // Filled
    });
});

// ──────────────────────────────────────────────────
// mergeDealData
// ──────────────────────────────────────────────────

describe('mergeDealData', function () {
    it('appends description to existing deal', function () {
        $deal = Deal::factory()->create(['description' => 'Original info']);

        $this->service->mergeDealData($deal, ['description' => 'New details from booking'], 'Booking Page');

        $deal->refresh();
        expect($deal->description)->toContain('Original info');
        expect($deal->description)->toContain('New details from booking');
        expect($deal->description)->toContain('Updated from Booking Page');
    });

    it('sets description when deal had none', function () {
        $deal = Deal::factory()->create(['description' => null]);

        $this->service->mergeDealData($deal, ['description' => 'First description'], 'Website');

        $deal->refresh();
        expect($deal->description)->toBe('First description');
    });

    it('sets last_merged_at and last_merged_source', function () {
        $deal = Deal::factory()->create();

        $this->service->mergeDealData($deal, [], 'Door Knock');

        $deal->refresh();
        expect($deal->last_merged_at)->not->toBeNull();
        expect($deal->last_merged_source)->toBe('Door Knock');
    });

    it('does not overwrite assigned_to when already set', function () {
        $assignee = User::factory()->internal()->create();
        $deal = Deal::factory()->create(['assigned_to' => $assignee->id]);

        $newUser = User::factory()->internal()->create();
        $this->service->mergeDealData($deal, ['assigned_to' => $newUser->id], 'Test');

        $deal->refresh();
        expect($deal->assigned_to)->toBe($assignee->id);
    });

    it('fills assigned_to when null', function () {
        $deal = Deal::factory()->create(['assigned_to' => null]);
        $newUser = User::factory()->internal()->create();

        $this->service->mergeDealData($deal, ['assigned_to' => $newUser->id], 'Test');

        $deal->refresh();
        expect($deal->assigned_to)->toBe($newUser->id);
    });

    it('always updates appointment_booking_id', function () {
        $deal = Deal::factory()->create(['appointment_booking_id' => null]);

        $this->service->mergeDealData($deal, ['appointment_booking_id' => null], 'Booking');

        $deal->refresh();
        // Verify merge tracking was set even without a booking update
        expect($deal->last_merged_at)->not->toBeNull();
        expect($deal->last_merged_source)->toBe('Booking');
    });

    it('logs activity on merge', function () {
        $deal = Deal::factory()->create();

        $this->service->mergeDealData($deal, ['description' => 'New info'], 'Booking Page');

        $activity = Activity::where('subject_type', Deal::class)
            ->where('subject_id', $deal->id)
            ->latest()
            ->first();

        expect($activity)->not->toBeNull();
        expect($activity->title)->toContain('Lead auto-merged from Booking Page');
        expect($activity->description)->toContain('description');
    });

    it('preserves lead_source (first-touch attribution)', function () {
        $deal = Deal::factory()->create(['lead_source' => 'website']);

        $this->service->mergeDealData($deal, ['lead_source' => 'vendor_booking'], 'Booking');

        $deal->refresh();
        expect($deal->lead_source)->toBe('website');
    });

    it('does not change deal stage on merge', function () {
        $deal = Deal::factory()->quoted()->create();

        $this->service->mergeDealData($deal, ['stage' => 'lead'], 'Test');

        $deal->refresh();
        expect($deal->stage)->toBe('quoted');
    });
});

// ──────────────────────────────────────────────────
// Cross-flow integration tests
// ──────────────────────────────────────────────────

describe('cross-flow deduplication', function () {
    it('landing page then booking same email = 1 deal', function () {
        // First: landing page creates customer + deal
        $result1 = $this->service->resolveOrCreate(
            customerData: ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@cross.com', 'source' => 'online', 'status' => 'active', 'created_by' => $this->internalUser->id],
            dealData: ['title' => 'Landing Page Lead', 'stage' => 'lead', 'lead_source' => 'website', 'created_by' => $this->internalUser->id],
        );

        expect($result1->dealWasExisting)->toBeFalse();

        // Second: booking with same email
        $result2 = $this->service->resolveOrCreate(
            customerData: ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@cross.com', 'phone' => '303-555-1111', 'status' => 'active', 'created_by' => $this->internalUser->id],
            dealData: ['title' => 'Booking Lead', 'stage' => 'lead', 'lead_source' => 'vendor_booking', 'created_by' => $this->internalUser->id],
            options: ['source_label' => 'Booking Page'],
        );

        expect($result2->dealWasExisting)->toBeTrue();
        expect($result2->deal->id)->toBe($result1->deal->id);
        expect(Deal::where('customer_id', $result1->customer->id)->count())->toBe(1);

        // Verify merge tracking
        $result2->deal->refresh();
        expect($result2->deal->last_merged_at)->not->toBeNull();
        expect($result2->deal->last_merged_source)->toBe('Booking Page');

        // Verify first-touch attribution preserved
        expect($result2->deal->lead_source)->toBe('website');
    });

    it('booking then landing page same email = 1 deal', function () {
        $result1 = $this->service->resolveOrCreate(
            customerData: ['first_name' => 'Bob', 'last_name' => 'Smith', 'email' => 'bob@cross.com', 'status' => 'active', 'created_by' => $this->internalUser->id],
            dealData: ['title' => 'Booking Lead', 'stage' => 'lead', 'lead_source' => 'vendor_booking', 'created_by' => $this->internalUser->id],
        );

        $result2 = $this->service->resolveOrCreate(
            customerData: ['first_name' => 'Bob', 'last_name' => 'Smith', 'email' => 'bob@cross.com', 'status' => 'active', 'created_by' => $this->internalUser->id],
            dealData: ['title' => 'Website Lead', 'stage' => 'lead', 'lead_source' => 'website', 'created_by' => $this->internalUser->id],
            options: ['source_label' => 'Website Lead Form'],
        );

        expect($result2->dealWasExisting)->toBeTrue();
        expect($result2->deal->id)->toBe($result1->deal->id);
        expect($result2->deal->lead_source)->toBe('vendor_booking'); // First-touch preserved
    });

    it('landing page with completed deal = new deal created', function () {
        $customer = Customer::factory()->create(['email' => 'completed@test.com']);
        Deal::factory()->forCustomer($customer)->completed()->create();

        $result = $this->service->resolveOrCreate(
            customerData: ['email' => 'completed@test.com', 'first_name' => 'Test', 'last_name' => 'User', 'status' => 'active', 'created_by' => $this->internalUser->id],
            dealData: ['title' => 'New Project', 'stage' => 'lead', 'lead_source' => 'website', 'created_by' => $this->internalUser->id],
        );

        expect($result->customerWasExisting)->toBeTrue();
        expect($result->dealWasExisting)->toBeFalse();
        expect(Deal::where('customer_id', $customer->id)->count())->toBe(2);
    });

    it('two different emails = separate deals (no false merge)', function () {
        $result1 = $this->service->resolveOrCreate(
            customerData: ['first_name' => 'Alice', 'last_name' => 'A', 'email' => 'alice@test.com', 'status' => 'active', 'created_by' => $this->internalUser->id],
            dealData: ['title' => 'Alice Deal', 'stage' => 'lead', 'lead_source' => 'website', 'created_by' => $this->internalUser->id],
        );

        $result2 = $this->service->resolveOrCreate(
            customerData: ['first_name' => 'Bob', 'last_name' => 'B', 'email' => 'bob@test.com', 'status' => 'active', 'created_by' => $this->internalUser->id],
            dealData: ['title' => 'Bob Deal', 'stage' => 'lead', 'lead_source' => 'website', 'created_by' => $this->internalUser->id],
        );

        expect($result1->deal->id)->not->toBe($result2->deal->id);
        expect($result1->customer->id)->not->toBe($result2->customer->id);
    });

    it('phone match across flows = 1 deal', function () {
        // First flow: customer with phone only (no email)
        $result1 = $this->service->resolveOrCreate(
            customerData: ['first_name' => 'Phone', 'last_name' => 'User', 'phone' => '303-555-7777', 'status' => 'active', 'created_by' => $this->internalUser->id],
            dealData: ['title' => 'Phone Lead', 'stage' => 'lead', 'lead_source' => 'door_knock', 'created_by' => $this->internalUser->id],
        );

        // Second flow: same phone, different format
        $result2 = $this->service->resolveOrCreate(
            customerData: ['first_name' => 'Phone', 'last_name' => 'User', 'phone' => '(303) 555-7777', 'status' => 'active', 'created_by' => $this->internalUser->id],
            dealData: ['title' => 'Should Merge', 'stage' => 'lead', 'lead_source' => 'website', 'created_by' => $this->internalUser->id],
            options: ['source_label' => 'Website'],
        );

        expect($result2->customerWasExisting)->toBeTrue();
        expect($result2->dealWasExisting)->toBeTrue();
        expect($result2->deal->id)->toBe($result1->deal->id);
    });
});

<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\CustomerDuplicateFlag;
use App\Models\Deal;
use App\Models\MessengerConversation;
use App\Models\Property;
use App\Models\User;
use App\Services\CustomerMergeService;

beforeEach(function () {
    $this->service = new CustomerMergeService;
    $this->resolver = User::factory()->internal()->create();
});

describe('merge', function () {
    it('reassigns deals from absorbed to primary customer', function () {
        $primary = Customer::factory()->create();
        $absorbed = Customer::factory()->create();

        $deal1 = Deal::factory()->forCustomer($absorbed)->create();
        $deal2 = Deal::factory()->forCustomer($absorbed)->create();
        $primaryDeal = Deal::factory()->forCustomer($primary)->create();

        $this->service->merge($primary, $absorbed, [], $this->resolver);

        expect(Deal::where('customer_id', $primary->id)->count())->toBe(3)
            ->and($deal1->fresh()->customer_id)->toBe($primary->id)
            ->and($deal2->fresh()->customer_id)->toBe($primary->id)
            ->and($primaryDeal->fresh()->customer_id)->toBe($primary->id);
    });

    it('reassigns properties from absorbed to primary', function () {
        $primary = Customer::factory()->create();
        $absorbed = Customer::factory()->create();

        $property = Property::factory()->forCustomer($absorbed)->create();

        $this->service->merge($primary, $absorbed, [], $this->resolver);

        expect($property->fresh()->customer_id)->toBe($primary->id);
    });

    it('applies field selections to primary customer', function () {
        $primary = Customer::factory()->create(['phone' => '111-111-1111']);
        $absorbed = Customer::factory()->create(['phone' => '999-999-9999']);

        $result = $this->service->merge($primary, $absorbed, [
            'phone' => $absorbed->phone,
        ], $this->resolver);

        expect($result->phone)->toBe('999-999-9999');
    });

    it('soft-deletes the absorbed customer', function () {
        $primary = Customer::factory()->create();
        $absorbed = Customer::factory()->create();
        $absorbedId = $absorbed->id;

        $this->service->merge($primary, $absorbed, [], $this->resolver);

        expect(Customer::find($absorbedId))->toBeNull()
            ->and(Customer::withTrashed()->find($absorbedId))->not->toBeNull()
            ->and(Customer::withTrashed()->find($absorbedId)->deleted_at)->not->toBeNull();
    });

    it('marks all related duplicate flags as merged with resolver info', function () {
        $primary = Customer::factory()->create();
        $absorbed = Customer::factory()->create();

        $flag1 = CustomerDuplicateFlag::factory()->create([
            'customer_id' => $primary->id,
            'duplicate_customer_id' => $absorbed->id,
        ]);
        $flag2 = CustomerDuplicateFlag::factory()->create([
            'customer_id' => $absorbed->id,
            'duplicate_customer_id' => $primary->id,
        ]);

        $this->service->merge($primary, $absorbed, [], $this->resolver);

        $flag1->refresh();
        $flag2->refresh();

        expect($flag1->status)->toBe('merged')
            ->and($flag1->resolved_by)->toBe($this->resolver->id)
            ->and($flag1->resolved_at)->not->toBeNull()
            ->and($flag2->status)->toBe('merged')
            ->and($flag2->resolved_by)->toBe($this->resolver->id)
            ->and($flag2->resolved_at)->not->toBeNull();
    });

    it('creates an activity log entry for the merge containing absorbed customer name', function () {
        $primary = Customer::factory()->create();
        $absorbed = Customer::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        $this->service->merge($primary, $absorbed, [], $this->resolver);

        $activity = Activity::where('subject_type', Customer::class)
            ->where('subject_id', $primary->id)
            ->where('type', 'customer_merged')
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->user_id)->toBe($this->resolver->id)
            ->and($activity->description)->toContain('Jane Doe')
            ->and($activity->description)->toContain((string) $absorbed->id);
    });

    it('reassigns messenger conversations from absorbed to primary', function () {
        $primary = Customer::factory()->create();
        $absorbed = Customer::factory()->create();

        $conversation = MessengerConversation::factory()->create([
            'customer_id' => $absorbed->id,
        ]);

        $this->service->merge($primary, $absorbed, [], $this->resolver);

        expect($conversation->fresh()->customer_id)->toBe($primary->id);
    });

    it('transfers portal user_id when primary has none but absorbed does', function () {
        $portalUser = User::factory()->customer()->create();
        $primary = Customer::factory()->create(['user_id' => null]);
        $absorbed = Customer::factory()->create(['user_id' => $portalUser->id]);

        $result = $this->service->merge($primary, $absorbed, [], $this->resolver);

        expect($result->user_id)->toBe($portalUser->id);
    });

    it('keeps primary user_id when both have portal access', function () {
        $primaryPortalUser = User::factory()->customer()->create();
        $absorbedPortalUser = User::factory()->customer()->create();
        $primary = Customer::factory()->create(['user_id' => $primaryPortalUser->id]);
        $absorbed = Customer::factory()->create(['user_id' => $absorbedPortalUser->id]);

        $result = $this->service->merge($primary, $absorbed, [], $this->resolver);

        expect($result->user_id)->toBe($primaryPortalUser->id);
    });
});

describe('getMergeSummary', function () {
    it('returns correct counts of deals, properties, conversations, and activities', function () {
        $primary = Customer::factory()->minimal()->create();
        $absorbed = Customer::factory()->minimal()->create();

        Deal::factory()->forCustomer($absorbed)->count(3)->create();
        Property::factory()->forCustomer($absorbed)->count(2)->create();
        MessengerConversation::factory()->count(1)->create([
            'customer_id' => $absorbed->id,
        ]);
        Activity::create([
            'subject_type' => Customer::class,
            'subject_id' => $absorbed->id,
            'user_id' => User::factory()->internal()->create()->id,
            'type' => 'note',
            'title' => 'Test note',
            'description' => 'Test activity',
        ]);

        $summary = $this->service->getMergeSummary($primary, $absorbed);

        expect($summary)->toBe([
            'deals' => 3,
            'properties' => 2,
            'conversations' => 1,
            'activities' => 1,
        ]);
    });
});

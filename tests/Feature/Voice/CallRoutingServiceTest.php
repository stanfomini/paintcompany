<?php

use App\Models\CallGroup;
use App\Models\CallGroupMember;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\User;
use App\Services\CallRoutingService;
use Illuminate\Support\Carbon;

beforeEach(function () {
    CompanySetting::clearInstanceCache();
    config([
        'services.twilio.enabled' => true,
        'services.twilio.sid' => 'test-sid',
        'services.twilio.token' => 'test-token',
        'services.twilio.from' => '+15551234567',
    ]);
});

afterEach(function () {
    CompanySetting::clearInstanceCache();
});

it('resolves default call group when no smart routing matches', function () {
    $defaultGroup = CallGroup::factory()->create(['is_active' => true]);
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update(['default_call_group_id' => $defaultGroup->id]);
    CompanySetting::clearInstanceCache();

    $service = app(CallRoutingService::class);
    $result = $service->resolveCallGroup('+13035559999');

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($defaultGroup->id);
});

it('returns null when no call groups configured', function () {
    $service = app(CallRoutingService::class);
    $result = $service->resolveCallGroup('+13035559999');

    expect($result)->toBeNull();
});

it('returns true for business hours during configured hours', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'timezone' => 'America/Denver',
        'phone_business_hours_start' => '08:00',
        'phone_business_hours_end' => '18:00',
        'phone_business_days' => [1, 2, 3, 4, 5],
    ]);
    CompanySetting::clearInstanceCache();

    // Freeze time to a known weekday during business hours
    // Wednesday at 10:00 AM Denver time
    Carbon::setTestNow(Carbon::create(2026, 3, 11, 10, 0, 0, 'America/Denver'));

    $service = app(CallRoutingService::class);

    expect($service->isBusinessHours())->toBeTrue();

    Carbon::setTestNow();
});

it('returns false for business hours outside configured hours', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'timezone' => 'America/Denver',
        'phone_business_hours_start' => '08:00',
        'phone_business_hours_end' => '18:00',
        'phone_business_days' => [1, 2, 3, 4, 5],
    ]);
    CompanySetting::clearInstanceCache();

    // Freeze time to a known weekday but after hours
    // Wednesday at 9:00 PM Denver time
    Carbon::setTestNow(Carbon::create(2026, 3, 11, 21, 0, 0, 'America/Denver'));

    $service = app(CallRoutingService::class);

    expect($service->isBusinessHours())->toBeFalse();

    Carbon::setTestNow();
});

it('returns false for business hours on non-business day', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'timezone' => 'America/Denver',
        'phone_business_hours_start' => '08:00',
        'phone_business_hours_end' => '18:00',
        'phone_business_days' => [1, 2, 3, 4, 5], // Mon-Fri only
    ]);
    CompanySetting::clearInstanceCache();

    // Freeze time to Saturday at 10:00 AM Denver time
    Carbon::setTestNow(Carbon::create(2026, 3, 14, 10, 0, 0, 'America/Denver'));

    $service = app(CallRoutingService::class);

    expect($service->isBusinessHours())->toBeFalse();

    Carbon::setTestNow();
});

it('returns true when no business hours configured (always open)', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'phone_business_hours_start' => null,
        'phone_business_hours_end' => null,
    ]);
    CompanySetting::clearInstanceCache();

    $service = app(CallRoutingService::class);

    expect($service->isBusinessHours())->toBeTrue();
});

it('finds user by phone number', function () {
    $user = User::factory()->internal()->create(['phone' => '+13035551234']);

    $service = app(CallRoutingService::class);
    $identity = $service->findCallerIdentity('+13035551234');

    expect($identity['user'])->not->toBeNull()
        ->and($identity['user']->id)->toBe($user->id);
});

it('finds customer by phone number', function () {
    $customer = Customer::factory()->create(['phone' => '+13035554321']);

    $service = app(CallRoutingService::class);
    $identity = $service->findCallerIdentity('+13035554321');

    expect($identity['customer'])->not->toBeNull()
        ->and($identity['customer']->id)->toBe($customer->id);
});

it('returns nulls for unknown phone number', function () {
    $service = app(CallRoutingService::class);
    $identity = $service->findCallerIdentity('+19995559999');

    expect($identity['user'])->toBeNull()
        ->and($identity['customer'])->toBeNull();
});

it('routes to after hours group outside business hours', function () {
    $defaultGroup = CallGroup::factory()->create(['name' => 'Default', 'is_active' => true]);
    $afterHoursGroup = CallGroup::factory()->create(['name' => 'After Hours', 'is_active' => true]);

    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'default_call_group_id' => $defaultGroup->id,
        'after_hours_call_group_id' => $afterHoursGroup->id,
        'timezone' => 'America/Denver',
        'phone_business_hours_start' => '08:00',
        'phone_business_hours_end' => '18:00',
        'phone_business_days' => [1, 2, 3, 4, 5],
    ]);
    CompanySetting::clearInstanceCache();

    // Wednesday at 9:00 PM Denver time (after hours)
    Carbon::setTestNow(Carbon::create(2026, 3, 11, 21, 0, 0, 'America/Denver'));

    $service = app(CallRoutingService::class);
    $result = $service->resolveCallGroup('+19995559999');

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($afterHoursGroup->id);

    Carbon::setTestNow();
});

it('routes to smart-matched group when customer has assigned estimator', function () {
    $estimator = User::factory()->internal()->create([
        'phone' => '+13035550001',
        'is_active' => true,
    ]);
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create([
        'user_id' => $customerUser->id,
        'phone' => '+13035557777',
        'assigned_to' => $estimator->id,
    ]);

    $estimatorGroup = CallGroup::factory()->create(['name' => 'Estimator Group', 'is_active' => true]);
    CallGroupMember::create([
        'call_group_id' => $estimatorGroup->id,
        'user_id' => $estimator->id,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $defaultGroup = CallGroup::factory()->create(['name' => 'Default', 'is_active' => true]);
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update(['default_call_group_id' => $defaultGroup->id]);
    CompanySetting::clearInstanceCache();

    $service = app(CallRoutingService::class);
    $result = $service->resolveCallGroup('+13035557777');

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($estimatorGroup->id);
});

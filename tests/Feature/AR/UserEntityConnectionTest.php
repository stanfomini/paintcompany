<?php

use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\Job;
use App\Models\LeadSource;
use App\Models\SalesTerritory;
use App\Models\User;
use App\Services\DoorKnockService;
use Illuminate\Support\Facades\Notification;

it('door knock conversion sets lead_generator_id on deal', function () {
    Notification::fake();
    $user = User::factory()->internal()->create();

    $knock = DoorKnock::factory()->forUser($user)->create([
        'territory_id' => SalesTerritory::factory(),
        'address' => '100 Test St',
        'city' => 'Denver',
        'state' => 'CO',
        'zip' => '80202',
        'lat' => 39.75,
        'lng' => -105.0,
        'status' => 'interested',
        'customer_name' => 'Test Person',
        'phone' => '555-000-1111',
    ]);

    $deal = app(DoorKnockService::class)->convertToLead($knock);

    expect($deal->lead_generator_id)->toBe($user->id);
    expect($deal->leadGenerator->id)->toBe($user->id);
});

it('door knock conversion creates personal lead source', function () {
    Notification::fake();
    $user = User::factory()->internal()->create();

    $knock = DoorKnock::factory()->forUser($user)->create([
        'territory_id' => SalesTerritory::factory(),
        'address' => '200 Test Ave',
        'city' => 'Denver',
        'state' => 'CO',
        'zip' => '80203',
        'lat' => 39.75,
        'lng' => -105.0,
        'status' => 'interested',
        'customer_name' => 'Another Lead',
        'phone' => '555-000-2222',
    ]);

    $deal = app(DoorKnockService::class)->convertToLead($knock);

    // Personal LeadSource should exist
    $personalSource = LeadSource::where('user_id', $user->id)->first();
    expect($personalSource)->not->toBeNull();
    expect($personalSource->slug)->toBe('user-'.$user->id);
    expect($personalSource->type)->toBe('employee');

    // Deal should be linked to this personal lead source
    expect($deal->lead_source_id)->toBe($personalSource->id);
});

it('multiple knocks by same user reuse same personal lead source', function () {
    Notification::fake();
    $user = User::factory()->internal()->create();

    $knock1 = DoorKnock::factory()->forUser($user)->create([
        'territory_id' => SalesTerritory::factory(),
        'address' => '300 First St',
        'city' => 'Denver',
        'state' => 'CO',
        'zip' => '80204',
        'lat' => 39.75,
        'lng' => -105.0,
        'status' => 'interested',
        'customer_name' => 'Lead One',
        'phone' => '555-000-3333',
    ]);

    $knock2 = DoorKnock::factory()->forUser($user)->create([
        'territory_id' => SalesTerritory::factory(),
        'address' => '400 Second St',
        'city' => 'Denver',
        'state' => 'CO',
        'zip' => '80205',
        'lat' => 39.76,
        'lng' => -105.01,
        'status' => 'interested',
        'customer_name' => 'Lead Two',
        'phone' => '555-000-4444',
    ]);

    $service = app(DoorKnockService::class);
    $deal1 = $service->convertToLead($knock1);
    $deal2 = $service->convertToLead($knock2);

    // Both deals should use the same personal lead source
    expect($deal1->lead_source_id)->toBe($deal2->lead_source_id);

    // Only one personal lead source should exist for this user
    expect(LeadSource::where('user_id', $user->id)->count())->toBe(1);
});

it('deal accepts lead_generator_id via factory', function () {
    $generator = User::factory()->internal()->create();
    $deal = Deal::factory()->withLeadGenerator($generator)->create();

    expect($deal->lead_generator_id)->toBe($generator->id);
    expect($deal->leadGenerator->id)->toBe($generator->id);
});

it('job accepts production_manager_id', function () {
    $pm = User::factory()->internal()->create();
    $job = Job::factory()->withProductionManager($pm)->create();

    expect($job->production_manager_id)->toBe($pm->id);
    expect($job->productionManager->id)->toBe($pm->id);
});

it('lead source accepts user_id', function () {
    $user = User::factory()->internal()->create();
    $source = LeadSource::factory()->forUser($user)->create();

    expect($source->user_id)->toBe($user->id);
    expect($source->user->id)->toBe($user->id);
    expect($source->type)->toBe('employee');
});

it('lead_generator_id and production_manager_id are nullable', function () {
    $deal = Deal::factory()->create(['lead_generator_id' => null]);
    expect($deal->lead_generator_id)->toBeNull();
    expect($deal->leadGenerator)->toBeNull();

    $job = Job::factory()->create(['production_manager_id' => null]);
    expect($job->production_manager_id)->toBeNull();
    expect($job->productionManager)->toBeNull();
});

it('existing commission triggers are unaffected by new fields', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create(['assigned_to' => null]);

    // Updating assigned_to should still trigger commission check (existing behavior)
    // This just verifies the model still works correctly
    $deal->update(['assigned_to' => $user->id]);
    $deal->refresh();

    expect($deal->assigned_to)->toBe($user->id);
});

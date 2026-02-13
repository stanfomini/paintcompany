<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Activity Calendar JSON API', function () {
    it('returns activities as JSON calendar events', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $activity = Activity::factory()->task()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Test Calendar Event',
            'due_at' => now()->setTime(10, 0),
        ]);

        $response = $this->actingAs($user)->getJson(route('activities.calendar', [
            'start' => now()->startOfDay()->toDateTimeString(),
            'end' => now()->endOfDay()->toDateTimeString(),
        ]));

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $activity->id,
                'title' => 'Test Calendar Event',
            ]);
    });

    it('filters activities by date range', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'In Range',
            'due_at' => now()->setTime(10, 0),
        ]);

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Out of Range',
            'due_at' => now()->addMonth()->setTime(10, 0),
        ]);

        $response = $this->actingAs($user)->getJson(route('activities.calendar', [
            'start' => now()->startOfDay()->toDateTimeString(),
            'end' => now()->endOfDay()->toDateTimeString(),
        ]));

        $response->assertOk()
            ->assertJsonFragment(['title' => 'In Range'])
            ->assertJsonMissing(['title' => 'Out of Range']);
    });

    it('requires planner.view permission', function () {
        $user = User::factory()->internal()->create();

        $response = $this->actingAs($user)->getJson(route('activities.calendar'));

        $response->assertForbidden();
    });

    it('includes subject URL in event data', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'assigned_to' => $user->id,
        ]);

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'title' => 'Deal Activity',
            'due_at' => now()->setTime(10, 0),
        ]);

        $response = $this->actingAs($user)->getJson(route('activities.calendar', [
            'start' => now()->startOfDay()->toDateTimeString(),
            'end' => now()->endOfDay()->toDateTimeString(),
        ]));

        $response->assertOk();
        $data = $response->json();
        expect($data)->toHaveCount(1);
        expect($data[0]['url'])->toContain('/customers/');
        expect($data[0]['extendedProps']['subject_label'])->toBe($customer->full_name);
    });
});

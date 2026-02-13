<?php

use App\Models\Activity;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    CompanySetting::create([
        'company_name' => 'Test Company',
        'company_email' => 'test@example.com',
    ]);
});

describe('Activity Calendar ICS Feed', function () {
    it('returns ICS feed for valid token', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $token = $user->getOrCreateCalendarToken();

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Test ICS Activity',
            'due_at' => now()->addDay()->setTime(10, 0),
        ]);

        $response = $this->get(route('calendar.activities', ['token' => $token]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

        expect($response->content())->toContain('BEGIN:VCALENDAR');
        expect($response->content())->toContain('Test ICS Activity');
    });

    it('returns 404 for invalid token', function () {
        $response = $this->get(route('calendar.activities', ['token' => 'invalid-token-123']));

        $response->assertNotFound();
    });

    it('includes activities with due dates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $token = $user->getOrCreateCalendarToken();

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Activity With Date',
            'due_at' => now()->addDay()->setTime(14, 0),
            'is_completed' => false,
        ]);

        // Activity without due_at should not appear
        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Activity No Date',
            'due_at' => null,
            'is_completed' => false,
        ]);

        $response = $this->get(route('calendar.activities', ['token' => $token]));

        expect($response->content())->toContain('Activity With Date');
        expect($response->content())->not->toContain('Activity No Date');
    });

    it('excludes old completed activities', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $token = $user->getOrCreateCalendarToken();

        // Recently completed - should appear
        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Recently Completed',
            'due_at' => now()->subDays(2),
            'is_completed' => true,
            'completed_at' => now()->subDays(1),
        ]);

        // Old completed - should not appear
        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Old Completed',
            'due_at' => now()->subDays(10),
            'is_completed' => true,
            'completed_at' => now()->subDays(9),
        ]);

        $response = $this->get(route('calendar.activities', ['token' => $token]));

        expect($response->content())->toContain('Recently Completed');
        expect($response->content())->not->toContain('Old Completed');
    });

    it('includes URL to parent entity', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $token = $user->getOrCreateCalendarToken();

        $customer = Customer::factory()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'assigned_to' => $user->id,
        ]);

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'title' => 'Deal Activity ICS',
            'due_at' => now()->addDay()->setTime(10, 0),
        ]);

        $response = $this->get(route('calendar.activities', ['token' => $token]));

        expect($response->content())->toContain('Deal Activity ICS');
        expect($response->content())->toContain('URL:');
    });
});

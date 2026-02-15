<?php

use App\Models\Activity;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\Property;
use App\Models\User;
use App\Models\VideoCall;

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

    it('includes job URL and address in job events', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $token = $user->getOrCreateCalendarToken();

        $customer = Customer::factory()->createdBy($user)->create();
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '123 Paint St',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
        ]);
        $deal = Deal::factory()->forCustomer($customer)->forProperty($property)->create([
            'assigned_to' => $user->id,
        ]);
        Job::factory()->create([
            'deal_id' => $deal->id,
            'scheduled_date' => now()->addDays(3)->toDateString(),
            'stage' => 'job_prep',
            'title' => 'Kitchen Repaint',
        ]);

        $response = $this->get(route('calendar.activities', ['token' => $token]));
        $content = $response->content();

        expect($content)->toContain('Kitchen Repaint');
        expect($content)->toContain('URL:');
        expect($content)->toContain('LOCATION:123 Paint St');
    });

    it('includes location for in-person meeting activities', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $token = $user->getOrCreateCalendarToken();

        $customer = Customer::factory()->createdBy($user)->create();
        $property = Property::factory()->create([
            'customer_id' => $customer->id,
            'address' => '456 Elm Ave',
            'city' => 'Boulder',
            'state' => 'CO',
            'zip' => '80301',
        ]);
        $deal = Deal::factory()->forCustomer($customer)->forProperty($property)->create([
            'assigned_to' => $user->id,
        ]);

        Activity::factory()->scheduledMeeting('in_person')->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'title' => 'Site Walk ICS',
            'due_at' => now()->addDay()->setTime(10, 0),
        ]);

        $response = $this->get(route('calendar.activities', ['token' => $token]));
        $content = $response->content();

        expect($content)->toContain('Site Walk ICS');
        expect($content)->toContain('LOCATION:456 Elm Ave');
    });

    it('includes virtual meeting URL as location', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $token = $user->getOrCreateCalendarToken();

        $job = Job::factory()->create([
            'deal_id' => Deal::factory()->create(['assigned_to' => $user->id]),
        ]);
        $videoCall = VideoCall::factory()->create([
            'job_id' => $job->id,
            'room_id' => 'test-room-abc',
        ]);

        Activity::factory()->scheduledMeeting('virtual')->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Virtual Consult ICS',
            'due_at' => now()->addDay()->setTime(14, 0),
            'video_call_id' => $videoCall->id,
        ]);

        $response = $this->get(route('calendar.activities', ['token' => $token]));
        $content = $response->content();

        expect($content)->toContain('Virtual Consult ICS');
        expect($content)->toContain('LOCATION:');
        expect($content)->toContain('Virtual Meeting');
    });

    it('includes subject URL in activity description', function () {
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
            'title' => 'Follow Up Task',
            'due_at' => now()->addDay()->setTime(10, 0),
        ]);

        $response = $this->get(route('calendar.activities', ['token' => $token]));

        expect($response->content())->toContain('View:');
    });
});

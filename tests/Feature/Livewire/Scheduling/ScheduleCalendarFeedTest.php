<?php

use App\Models\CompanySetting;
use App\Models\EmployeeProfile;
use App\Models\Job;
use App\Models\Schedule;
use App\Models\User;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    CompanySetting::create([
        'company_name' => 'Test Company',
        'company_email' => 'test@example.com',
    ]);
});

describe('Schedule Calendar ICS Feed', function () {
    it('returns ICS feed for valid token', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $token = $user->getOrCreateCalendarToken();

        Schedule::factory()->create([
            'employee_profile_id' => $profile->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
            'status' => 'scheduled',
        ]);

        $response = $this->get(route('calendar.schedule-feed', ['token' => $token]));

        $response->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8');

        expect($response->content())->toContain('BEGIN:VCALENDAR');
        expect($response->content())->toContain('Work Shift');
    });

    it('returns 404 for invalid token', function () {
        $response = $this->get(route('calendar.schedule-feed', ['token' => 'invalid-token-123']));

        $response->assertNotFound();
    });

    it('includes schedules with job info in title', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $token = $user->getOrCreateCalendarToken();

        $job = Job::factory()->create(['title' => 'Kitchen Repaint']);

        Schedule::factory()->create([
            'employee_profile_id' => $profile->id,
            'job_id' => $job->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
            'status' => 'confirmed',
        ]);

        $response = $this->get(route('calendar.schedule-feed', ['token' => $token]));

        expect($response->content())->toContain('Kitchen Repaint');
    });

    it('excludes cancelled schedules', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $token = $user->getOrCreateCalendarToken();

        Schedule::factory()->create([
            'employee_profile_id' => $profile->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
            'status' => 'scheduled',
            'notes' => 'Active shift note',
        ]);

        Schedule::factory()->cancelled()->create([
            'employee_profile_id' => $profile->id,
            'date' => now()->addDays(2)->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
            'notes' => 'Cancelled shift note',
        ]);

        $response = $this->get(route('calendar.schedule-feed', ['token' => $token]));

        expect($response->content())->toContain('Active shift note');
        expect($response->content())->not->toContain('Cancelled shift note');
    });

    it('returns empty calendar for user without employee profile', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $token = $user->getOrCreateCalendarToken();

        $response = $this->get(route('calendar.schedule-feed', ['token' => $token]));

        $response->assertOk();
        expect($response->content())->toContain('BEGIN:VCALENDAR');
        expect($response->content())->not->toContain('BEGIN:VEVENT');
    });

    it('includes break and notes in event description', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $profile = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $token = $user->getOrCreateCalendarToken();

        Schedule::factory()->create([
            'employee_profile_id' => $profile->id,
            'date' => now()->addDay()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
            'break_duration' => 30,
            'status' => 'confirmed',
            'notes' => 'Bring safety gear',
        ]);

        $response = $this->get(route('calendar.schedule-feed', ['token' => $token]));

        expect($response->content())->toContain('Break: 30 min');
        expect($response->content())->toContain('Bring safety gear');
    });
});

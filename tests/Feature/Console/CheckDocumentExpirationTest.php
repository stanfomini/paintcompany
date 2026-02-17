<?php

use App\Models\EmployeeDocument;
use App\Models\EmployeeProfile;
use App\Models\User;
use App\Notifications\DocumentExpiring;
use Illuminate\Support\Facades\Notification;

describe('documents:check-expiration command', function () {
    it('notifies employees about expiring documents', function () {
        Notification::fake();

        $employee = EmployeeProfile::factory()->create();
        $admin = User::factory()->internal()->withFullAccess()->create();

        EmployeeDocument::factory()->create([
            'employee_profile_id' => $employee->id,
            'status' => 'approved',
            'expires_at' => now()->addDays(15),
        ]);

        $this->artisan('documents:check-expiration')
            ->assertSuccessful();

        Notification::assertSentTo($employee->user, DocumentExpiring::class);
        Notification::assertSentTo($admin, DocumentExpiring::class);
    });

    it('does not notify about non-expiring documents', function () {
        Notification::fake();

        $employee = EmployeeProfile::factory()->create();
        User::factory()->internal()->withFullAccess()->create();

        EmployeeDocument::factory()->create([
            'employee_profile_id' => $employee->id,
            'status' => 'approved',
            'expires_at' => now()->addMonths(6),
        ]);

        $this->artisan('documents:check-expiration')
            ->assertSuccessful();

        Notification::assertNothingSent();
    });

    it('does not notify about pending documents even if expiring', function () {
        Notification::fake();

        $employee = EmployeeProfile::factory()->create();
        User::factory()->internal()->withFullAccess()->create();

        EmployeeDocument::factory()->create([
            'employee_profile_id' => $employee->id,
            'status' => 'pending',
            'expires_at' => now()->addDays(10),
        ]);

        $this->artisan('documents:check-expiration')
            ->assertSuccessful();

        Notification::assertNothingSent();
    });

    it('outputs info when no documents are expiring', function () {
        $this->artisan('documents:check-expiration')
            ->expectsOutput('No documents expiring soon.')
            ->assertSuccessful();
    });
});

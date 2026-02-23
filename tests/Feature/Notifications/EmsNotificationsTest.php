<?php

use App\Models\CompanySetting;
use App\Models\EmployeeContract;
use App\Models\EmployeeProfile;
use App\Models\PayPeriod;
use App\Models\PayStub;
use App\Models\PtoRequest;
use App\Models\Schedule;
use App\Models\User;
use App\Notifications\ContractSentForSigning;
use App\Notifications\PayStubAvailable;
use App\Notifications\PtoRequested;
use App\Notifications\PtoStatusChanged;
use App\Notifications\SchedulePublished;
use App\Services\ContractService;
use App\Services\PayrollService;
use App\Services\SchedulingService;
use Illuminate\Support\Facades\Notification;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    CompanySetting::getInstance();
});

// ─── PTO Requested ─────────────────────────────────────────────────────

describe('PtoRequested Notification', function () {
    it('is queued', function () {
        $notification = new PtoRequested(PtoRequest::factory()->make());

        expect($notification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    it('uses mail database and broadcast channels', function () {
        $notification = new PtoRequested(PtoRequest::factory()->make());
        $channels = $notification->via(User::factory()->make());

        expect($channels)->toBe(['mail', 'database', 'broadcast']);
    });

    it('has correct email subject with employee name', function () {
        $user = User::factory()->internal()->create(['name' => 'Jane Doe']);
        $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $pto = PtoRequest::factory()->create(['employee_profile_id' => $employee->id]);

        $notification = new PtoRequested($pto);
        $mail = $notification->toMail(User::factory()->make());

        expect($mail->subject)->toBe('Jane Doe requested PTO');
    });

    it('includes PTO details in email body', function () {
        $user = User::factory()->internal()->create(['name' => 'Jane Doe']);
        $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $pto = PtoRequest::factory()->create([
            'employee_profile_id' => $employee->id,
            'type' => 'vacation',
            'hours' => 16.0,
        ]);

        $notification = new PtoRequested($pto);
        $mail = $notification->toMail(User::factory()->make());

        $body = implode(' ', $mail->introLines);
        expect($body)->toContain('Vacation');
        expect($body)->toContain('16');
    });

    it('sends to managers when PTO is requested via service', function () {
        Notification::fake();

        $manager = User::factory()->internal()->withFullAccess()->create();
        $employeeUser = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create(['user_id' => $employeeUser->id]);

        $service = app(SchedulingService::class);
        $service->requestPto([
            'employee_profile_id' => $employee->id,
            'type' => 'vacation',
            'start_date' => now()->addWeek()->toDateString(),
            'end_date' => now()->addWeek()->addDays(2)->toDateString(),
            'hours' => 24.0,
            'status' => 'pending',
        ]);

        Notification::assertSentTo($manager, PtoRequested::class);
    });

    it('converts to array with correct keys', function () {
        $user = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create(['user_id' => $user->id]);
        $pto = PtoRequest::factory()->create(['employee_profile_id' => $employee->id]);

        $notification = new PtoRequested($pto);
        $array = $notification->toArray(User::factory()->make());

        expect($array)->toHaveKeys([
            'pto_request_id',
            'employee_name',
            'type',
            'start_date',
            'end_date',
            'hours',
        ]);
    });
});

// ─── PTO Status Changed ────────────────────────────────────────────────

describe('PtoStatusChanged Notification', function () {
    it('is queued', function () {
        $notification = new PtoStatusChanged(PtoRequest::factory()->make(), 'approved');

        expect($notification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    it('uses mail database and broadcast channels', function () {
        $notification = new PtoStatusChanged(PtoRequest::factory()->make(), 'approved');
        $channels = $notification->via(User::factory()->make());

        expect($channels)->toBe(['mail', 'database', 'broadcast']);
    });

    it('has correct subject for approval', function () {
        $pto = PtoRequest::factory()->make();

        $notification = new PtoStatusChanged($pto, 'approved');
        $mail = $notification->toMail(User::factory()->make());

        expect($mail->subject)->toBe('Your PTO request has been approved');
    });

    it('has correct subject for denial', function () {
        $pto = PtoRequest::factory()->make();

        $notification = new PtoStatusChanged($pto, 'denied', 'Staffing shortage');
        $mail = $notification->toMail(User::factory()->make());

        expect($mail->subject)->toBe('Your PTO request has been denied');
    });

    it('includes denial reason when denied', function () {
        $pto = PtoRequest::factory()->create();

        $notification = new PtoStatusChanged($pto, 'denied', 'Staffing shortage');
        $mail = $notification->toMail(User::factory()->make());

        $body = implode(' ', $mail->introLines);
        expect($body)->toContain('Staffing shortage');
    });

    it('does not include reason line when approved', function () {
        $pto = PtoRequest::factory()->create();

        $notification = new PtoStatusChanged($pto, 'approved');
        $mail = $notification->toMail(User::factory()->make());

        $body = implode(' ', $mail->introLines);
        expect($body)->not->toContain('Reason:');
    });

    it('sends to employee when PTO is approved via service', function () {
        Notification::fake();

        $employeeUser = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create(['user_id' => $employeeUser->id]);
        $pto = PtoRequest::factory()->create(['employee_profile_id' => $employee->id]);
        $approver = User::factory()->internal()->create();

        $service = app(SchedulingService::class);
        $service->approvePto($pto, $approver);

        Notification::assertSentTo($employeeUser, PtoStatusChanged::class, function ($notification) {
            return $notification->status === 'approved';
        });
    });

    it('sends to employee with reason when PTO is denied via service', function () {
        Notification::fake();

        $employeeUser = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create(['user_id' => $employeeUser->id]);
        $pto = PtoRequest::factory()->create(['employee_profile_id' => $employee->id]);
        $denier = User::factory()->internal()->create();

        $service = app(SchedulingService::class);
        $service->denyPto($pto, $denier, 'Too many people off');

        Notification::assertSentTo($employeeUser, PtoStatusChanged::class, function ($notification) {
            return $notification->status === 'denied' && $notification->reason === 'Too many people off';
        });
    });

    it('converts to array with correct keys', function () {
        $pto = PtoRequest::factory()->create();

        $notification = new PtoStatusChanged($pto, 'denied', 'No coverage');
        $array = $notification->toArray(User::factory()->make());

        expect($array)->toHaveKeys([
            'pto_request_id',
            'status',
            'type',
            'start_date',
            'end_date',
            'reason',
        ]);
        expect($array['status'])->toBe('denied');
        expect($array['reason'])->toBe('No coverage');
    });
});

// ─── Contract Sent For Signing ─────────────────────────────────────────

describe('ContractSentForSigning Notification', function () {
    it('is queued', function () {
        $notification = new ContractSentForSigning(EmployeeContract::factory()->make());

        expect($notification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    it('uses mail database and broadcast channels', function () {
        $notification = new ContractSentForSigning(EmployeeContract::factory()->make());
        $channels = $notification->via(User::factory()->make());

        expect($channels)->toBe(['mail', 'database', 'broadcast']);
    });

    it('has correct email subject with contract title', function () {
        $contract = EmployeeContract::factory()->make(['title' => 'Employment Agreement']);

        $notification = new ContractSentForSigning($contract);
        $mail = $notification->toMail(User::factory()->make());

        expect($mail->subject)->toBe('You have a contract to sign: Employment Agreement');
    });

    it('includes signing link', function () {
        $contract = EmployeeContract::factory()->create(['token' => 'abc-123-token']);

        $notification = new ContractSentForSigning($contract);
        $mail = $notification->toMail(User::factory()->make());

        expect($mail->actionUrl)->toBe(route('contracts.sign', 'abc-123-token'));
    });

    it('sends to employee when contract is sent via service', function () {
        Notification::fake();

        $employeeUser = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create(['user_id' => $employeeUser->id]);
        $contract = EmployeeContract::factory()->create(['employee_profile_id' => $employee->id]);

        $service = app(ContractService::class);
        $service->sendContract($contract);

        Notification::assertSentTo($employeeUser, ContractSentForSigning::class);
    });

    it('converts to array with correct keys', function () {
        $contract = EmployeeContract::factory()->create();

        $notification = new ContractSentForSigning($contract);
        $array = $notification->toArray(User::factory()->make());

        expect($array)->toHaveKeys(['contract_id', 'title', 'token']);
    });
});

// ─── Pay Stub Available ────────────────────────────────────────────────

describe('PayStubAvailable Notification', function () {
    it('is queued', function () {
        $notification = new PayStubAvailable(PayStub::factory()->make());

        expect($notification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    it('uses mail database and broadcast channels', function () {
        $notification = new PayStubAvailable(PayStub::factory()->make());
        $channels = $notification->via(User::factory()->make());

        expect($channels)->toBe(['mail', 'database', 'broadcast']);
    });

    it('has correct email subject', function () {
        $stub = PayStub::factory()->make();

        $notification = new PayStubAvailable($stub);
        $mail = $notification->toMail(User::factory()->make());

        expect($mail->subject)->toBe('Your pay stub is ready');
    });

    it('includes net pay in email body', function () {
        $period = PayPeriod::factory()->create();
        $stub = PayStub::factory()->create([
            'pay_period_id' => $period->id,
            'net_pay' => 1234.56,
        ]);

        $notification = new PayStubAvailable($stub);
        $mail = $notification->toMail(User::factory()->make());

        $body = implode(' ', $mail->introLines);
        expect($body)->toContain('1,234.56');
    });

    it('includes link to my pay stubs page', function () {
        $period = PayPeriod::factory()->create();
        $stub = PayStub::factory()->create(['pay_period_id' => $period->id]);

        $notification = new PayStubAvailable($stub);
        $mail = $notification->toMail(User::factory()->make());

        expect($mail->actionUrl)->toBe(route('my.pay-stubs'));
    });

    it('sends to employees when payroll is finalized via service', function () {
        Notification::fake();

        $employeeUser = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->withW4()->create([
            'user_id' => $employeeUser->id,
            'pay_rate' => 25.00,
            'pay_type' => 'hourly',
        ]);

        $period = PayPeriod::factory()->create(['status' => 'processing']);

        // Create a draft pay stub for the employee
        PayStub::factory()->create([
            'employee_profile_id' => $employee->id,
            'pay_period_id' => $period->id,
            'status' => 'draft',
        ]);

        $processor = User::factory()->internal()->create();

        $service = app(PayrollService::class);
        $service->finalizePayroll($period, $processor);

        Notification::assertSentTo($employeeUser, PayStubAvailable::class);
    });

    it('converts to array with correct keys', function () {
        $stub = PayStub::factory()->create();

        $notification = new PayStubAvailable($stub);
        $array = $notification->toArray(User::factory()->make());

        expect($array)->toHaveKeys(['pay_stub_id', 'pay_period_id', 'net_pay']);
    });
});

// ─── Schedule Published ────────────────────────────────────────────────

describe('SchedulePublished Notification', function () {
    it('is queued', function () {
        $notification = new SchedulePublished(Schedule::factory()->make());

        expect($notification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    it('uses mail database and broadcast channels', function () {
        $notification = new SchedulePublished(Schedule::factory()->make());
        $channels = $notification->via(User::factory()->make());

        expect($channels)->toBe(['mail', 'database', 'broadcast']);
    });

    it('has correct email subject with date', function () {
        $schedule = Schedule::factory()->make([
            'date' => '2026-03-15',
        ]);

        $notification = new SchedulePublished($schedule);
        $mail = $notification->toMail(User::factory()->make());

        expect($mail->subject)->toBe('New schedule: Mar 15, 2026');
    });

    it('includes time range in email body', function () {
        $schedule = Schedule::factory()->create([
            'start_time' => '08:00',
            'end_time' => '16:00',
        ]);

        $notification = new SchedulePublished($schedule);
        $mail = $notification->toMail(User::factory()->make());

        $body = implode(' ', $mail->introLines);
        expect($body)->toContain('08:00');
        expect($body)->toContain('16:00');
    });

    it('includes notes when present', function () {
        $schedule = Schedule::factory()->create([
            'notes' => 'Bring your own ladder',
        ]);

        $notification = new SchedulePublished($schedule);
        $mail = $notification->toMail(User::factory()->make());

        $body = implode(' ', $mail->introLines);
        expect($body)->toContain('Bring your own ladder');
    });

    it('sends to employee when schedule is created via service', function () {
        Notification::fake();

        $employeeUser = User::factory()->internal()->create();
        $employee = EmployeeProfile::factory()->create(['user_id' => $employeeUser->id]);

        $service = app(SchedulingService::class);
        $service->createSchedule([
            'employee_profile_id' => $employee->id,
            'date' => now()->addWeek()->toDateString(),
            'start_time' => '08:00',
            'end_time' => '16:00',
            'break_duration' => 30,
            'status' => 'scheduled',
        ]);

        Notification::assertSentTo($employeeUser, SchedulePublished::class);
    });

    it('converts to array with correct keys', function () {
        $schedule = Schedule::factory()->create();

        $notification = new SchedulePublished($schedule);
        $array = $notification->toArray(User::factory()->make());

        expect($array)->toHaveKeys(['schedule_id', 'date', 'start_time', 'end_time']);
    });
});

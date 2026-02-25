<?php

use App\Jobs\AutoLinkEmailJob;
use App\Jobs\SendEmailJob;
use App\Jobs\SendWelcomeEmailJob;
use App\Models\Customer;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\User;
use App\Services\WelcomeEmailService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->seed(\Database\Seeders\EmailLabelSeeder::class);
    Queue::fake([SendEmailJob::class, AutoLinkEmailJob::class, SendWelcomeEmailJob::class]);
});

describe('WelcomeEmailService', function () {
    it('sends welcome email from assignee to customer', function () {
        $assignee = User::factory()->internal()->create();
        EmailAccount::factory()->create(['user_id' => $assignee->id, 'address' => 'matt']);

        $customer = Customer::factory()->create([
            'email' => 'john@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $service = app(WelcomeEmailService::class);
        $message = $service->sendWelcomeEmail($customer, $assignee);

        expect($message)->toBeInstanceOf(EmailMessage::class);
        expect($message->direction)->toBe('outbound');
        expect($message->subject)->toBe('Welcome to '.config('app.name').'!');
        expect($message->email_account_id)->toBe($assignee->emailAccount->id);
        expect($message->to_addresses)->toEqual([['address' => 'john@example.com', 'name' => 'John Doe']]);
        expect($message->body_html)->toContain('John');
        expect($message->body_html)->toContain($assignee->name);
    });

    it('does not send if customer has no email', function () {
        $assignee = User::factory()->internal()->create();
        EmailAccount::factory()->create(['user_id' => $assignee->id]);

        $customer = Customer::factory()->create(['email' => null]);

        $service = app(WelcomeEmailService::class);
        $result = $service->sendWelcomeEmail($customer, $assignee);

        expect($result)->toBeNull();
    });

    it('does not send if assignee has no email account', function () {
        $assignee = User::factory()->internal()->create();
        $customer = Customer::factory()->create(['email' => 'test@example.com']);

        $service = app(WelcomeEmailService::class);
        $result = $service->sendWelcomeEmail($customer, $assignee);

        expect($result)->toBeNull();
    });

    it('does not send if assignee is not internal', function () {
        $assignee = User::factory()->agent()->create();
        EmailAccount::factory()->create(['user_id' => $assignee->id]);

        $customer = Customer::factory()->create(['email' => 'test@example.com']);

        $service = app(WelcomeEmailService::class);
        $result = $service->sendWelcomeEmail($customer, $assignee);

        expect($result)->toBeNull();
    });

    it('does not re-send to same customer-assignee pair', function () {
        $assignee = User::factory()->internal()->create();
        $account = EmailAccount::factory()->create(['user_id' => $assignee->id, 'address' => 'matt']);

        $customer = Customer::factory()->create([
            'email' => 'john@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $service = app(WelcomeEmailService::class);

        $first = $service->sendWelcomeEmail($customer, $assignee);
        expect($first)->toBeInstanceOf(EmailMessage::class);

        $second = $service->sendWelcomeEmail($customer, $assignee);
        expect($second)->toBeNull();
    });
});

describe('CustomerObserver dispatches SendWelcomeEmailJob', function () {
    it('dispatches when customer is created with assigned_to', function () {
        $assignee = User::factory()->internal()->create();
        EmailAccount::factory()->create(['user_id' => $assignee->id]);

        Customer::factory()->create([
            'assigned_to' => $assignee->id,
            'email' => 'new@example.com',
        ]);

        Queue::assertPushed(SendWelcomeEmailJob::class, function ($job) use ($assignee) {
            return $job->assignee->id === $assignee->id;
        });
    });

    it('dispatches when assigned_to changes', function () {
        $assignee1 = User::factory()->internal()->create();
        EmailAccount::factory()->create(['user_id' => $assignee1->id]);

        $assignee2 = User::factory()->internal()->create();
        EmailAccount::factory()->create(['user_id' => $assignee2->id]);

        $customer = Customer::factory()->create([
            'assigned_to' => $assignee1->id,
            'email' => 'customer@example.com',
        ]);

        Queue::assertPushed(SendWelcomeEmailJob::class, 1);

        $customer->update(['assigned_to' => $assignee2->id]);

        Queue::assertPushed(SendWelcomeEmailJob::class, function ($job) use ($assignee2) {
            return $job->assignee->id === $assignee2->id;
        });
    });

    it('does not dispatch if customer has no email', function () {
        $assignee = User::factory()->internal()->create();
        EmailAccount::factory()->create(['user_id' => $assignee->id]);

        Customer::factory()->create([
            'assigned_to' => $assignee->id,
            'email' => null,
        ]);

        Queue::assertNotPushed(SendWelcomeEmailJob::class);
    });

    it('does not dispatch if assignee has no email account', function () {
        $assignee = User::factory()->internal()->create();

        Customer::factory()->create([
            'assigned_to' => $assignee->id,
            'email' => 'test@example.com',
        ]);

        Queue::assertNotPushed(SendWelcomeEmailJob::class);
    });

    it('does not dispatch if assignee is not internal', function () {
        $assignee = User::factory()->agent()->create();
        EmailAccount::factory()->create(['user_id' => $assignee->id]);

        Customer::factory()->create([
            'assigned_to' => $assignee->id,
            'email' => 'test@example.com',
        ]);

        Queue::assertNotPushed(SendWelcomeEmailJob::class);
    });
});

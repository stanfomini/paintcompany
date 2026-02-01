<?php

use App\Models\AgentListing;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\NewOpenHouseSignup;
use Illuminate\Support\Facades\Notification;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('NewOpenHouseSignup Notification', function () {
    it('is sent to the agent when a customer signs up', function () {
        Notification::fake();

        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();
        $customer = Customer::factory()->create([
            'listing_id' => $listing->id,
            'referred_by' => $agent->id,
        ]);

        $agent->notify(new NewOpenHouseSignup($customer, $listing));

        Notification::assertSentTo($agent, NewOpenHouseSignup::class);
    });

    it('contains correct customer information', function () {
        Notification::fake();

        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();
        $customer = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john.doe@example.com',
            'phone' => '555-123-4567',
            'listing_id' => $listing->id,
            'referred_by' => $agent->id,
        ]);

        $agent->notify(new NewOpenHouseSignup($customer, $listing));

        Notification::assertSentTo($agent, NewOpenHouseSignup::class, function ($notification) {
            expect($notification->customer->first_name)->toBe('John');
            expect($notification->customer->last_name)->toBe('Doe');
            expect($notification->customer->email)->toBe('john.doe@example.com');
            expect($notification->customer->phone)->toBe('555-123-4567');

            return true;
        });
    });

    it('contains correct listing information', function () {
        Notification::fake();

        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'address' => '123 Main St',
            'city' => 'Springfield',
            'state' => 'IL',
            'zip' => '62701',
        ]);
        $customer = Customer::factory()->create([
            'listing_id' => $listing->id,
            'referred_by' => $agent->id,
        ]);

        $agent->notify(new NewOpenHouseSignup($customer, $listing));

        Notification::assertSentTo($agent, NewOpenHouseSignup::class, function ($notification) {
            expect($notification->listing->address)->toBe('123 Main St');
            expect($notification->listing->city)->toBe('Springfield');
            expect($notification->listing->state)->toBe('IL');
            expect($notification->listing->zip)->toBe('62701');
            expect($notification->listing->full_address)->toBe('123 Main St, Springfield, IL 62701');

            return true;
        });
    });

    it('is sent to the correct agent', function () {
        Notification::fake();

        $agent1 = User::factory()->agent()->create();
        $agent2 = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent1)->create();
        $customer = Customer::factory()->create([
            'listing_id' => $listing->id,
            'referred_by' => $agent1->id,
        ]);

        $agent1->notify(new NewOpenHouseSignup($customer, $listing));

        Notification::assertSentTo($agent1, NewOpenHouseSignup::class);
        Notification::assertNotSentTo($agent2, NewOpenHouseSignup::class);
    });

    it('has correct view customer link', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();
        $customer = Customer::factory()->create([
            'listing_id' => $listing->id,
            'referred_by' => $agent->id,
        ]);

        $notification = new NewOpenHouseSignup($customer, $listing);
        $mailMessage = $notification->toMail($agent);

        expect($mailMessage->actionUrl)->toBe(url('/agent/customers/'.$customer->id));
    });

    it('has correct email subject with listing address', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'address' => '456 Oak Avenue',
        ]);
        $customer = Customer::factory()->create([
            'listing_id' => $listing->id,
            'referred_by' => $agent->id,
        ]);

        $notification = new NewOpenHouseSignup($customer, $listing);
        $mailMessage = $notification->toMail($agent);

        expect($mailMessage->subject)->toBe('New Open House Sign-up: 456 Oak Avenue');
    });

    it('greets agent by name', function () {
        $agent = User::factory()->agent()->create(['name' => 'Jane Smith']);
        $listing = AgentListing::factory()->forAgent($agent)->create();
        $customer = Customer::factory()->create([
            'listing_id' => $listing->id,
            'referred_by' => $agent->id,
        ]);

        $notification = new NewOpenHouseSignup($customer, $listing);
        $mailMessage = $notification->toMail($agent);

        expect($mailMessage->greeting)->toBe('Hello Jane Smith!');
    });

    it('shows not provided when phone is missing', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();
        $customer = Customer::factory()->create([
            'phone' => null,
            'listing_id' => $listing->id,
            'referred_by' => $agent->id,
        ]);

        $notification = new NewOpenHouseSignup($customer, $listing);
        $mailMessage = $notification->toMail($agent);

        $phoneLineFound = false;
        foreach ($mailMessage->introLines as $line) {
            if (str_contains($line, 'Phone:') && str_contains($line, 'Not provided')) {
                $phoneLineFound = true;
                break;
            }
        }

        expect($phoneLineFound)->toBeTrue();
    });

    it('implements ShouldQueue interface', function () {
        $notification = new NewOpenHouseSignup(
            Customer::factory()->make(),
            AgentListing::factory()->make()
        );

        expect($notification)->toBeInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class);
    });

    it('uses mail channel only', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create();
        $customer = Customer::factory()->create([
            'listing_id' => $listing->id,
            'referred_by' => $agent->id,
        ]);

        $notification = new NewOpenHouseSignup($customer, $listing);
        $channels = $notification->via($agent);

        expect($channels)->toBe(['mail']);
    });

    it('converts to array with correct structure', function () {
        $agent = User::factory()->agent()->create();
        $listing = AgentListing::factory()->forAgent($agent)->create([
            'address' => '789 Pine St',
        ]);
        $customer = Customer::factory()->create([
            'first_name' => 'Bob',
            'last_name' => 'Johnson',
            'listing_id' => $listing->id,
            'referred_by' => $agent->id,
        ]);

        $notification = new NewOpenHouseSignup($customer, $listing);
        $array = $notification->toArray($agent);

        expect($array)->toHaveKeys(['customer_id', 'listing_id', 'customer_name', 'listing_address']);
        expect($array['customer_id'])->toBe($customer->id);
        expect($array['listing_id'])->toBe($listing->id);
        expect($array['customer_name'])->toBe('Bob Johnson');
        expect($array['listing_address'])->toBe('789 Pine St');
    });
});

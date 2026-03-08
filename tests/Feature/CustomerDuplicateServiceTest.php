<?php

use App\Models\Customer;
use App\Models\CustomerDuplicateFlag;
use App\Models\User;
use App\Services\CustomerDuplicateService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new CustomerDuplicateService;
});

describe('findPotentialDuplicates', function () {
    it('finds duplicates by exact email match', function () {
        $existing = Customer::factory()->create(['email' => 'john@example.com']);

        $results = $this->service->findPotentialDuplicates(['email' => 'john@example.com']);

        expect($results['customers'])->toHaveCount(1);
        expect($results['customers']->first()['customer']->id)->toBe($existing->id);
        expect($results['customers']->first()['match_type'])->toBe('email');
    });

    it('finds duplicates by email case-insensitively', function () {
        $existing = Customer::factory()->create(['email' => 'John@Example.COM']);

        $results = $this->service->findPotentialDuplicates(['email' => 'john@example.com']);

        expect($results['customers'])->toHaveCount(1);
        expect($results['customers']->first()['customer']->id)->toBe($existing->id);
        expect($results['customers']->first()['match_type'])->toBe('email');
    });

    it('finds duplicates by normalized phone match', function () {
        $existing = Customer::factory()->create(['phone' => '(555) 123-4567']);

        $results = $this->service->findPotentialDuplicates(['phone' => '555-123-4567']);

        expect($results['customers'])->toHaveCount(1);
        expect($results['customers']->first()['customer']->id)->toBe($existing->id);
        expect($results['customers']->first()['match_type'])->toBe('phone');
    });

    it('finds duplicates by exact first and last name case-insensitively', function () {
        $existing = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $results = $this->service->findPotentialDuplicates([
            'first_name' => 'john',
            'last_name' => 'doe',
        ]);

        expect($results['customers'])->toHaveCount(1);
        expect($results['customers']->first()['customer']->id)->toBe($existing->id);
        expect($results['customers']->first()['match_type'])->toBe('name');
    });

    it('does not match on first name alone', function () {
        Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $results = $this->service->findPotentialDuplicates([
            'first_name' => 'John',
            'last_name' => 'Smith',
        ]);

        expect($results['customers'])->toHaveCount(0);
    });

    it('does not match empty or null fields', function () {
        Customer::factory()->create([
            'email' => 'test@example.com',
            'phone' => '555-123-4567',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        // Null email
        $results = $this->service->findPotentialDuplicates(['email' => null]);
        expect($results['customers'])->toHaveCount(0);

        // Empty email
        $results = $this->service->findPotentialDuplicates(['email' => '']);
        expect($results['customers'])->toHaveCount(0);

        // Null phone
        $results = $this->service->findPotentialDuplicates(['phone' => null]);
        expect($results['customers'])->toHaveCount(0);

        // Empty first name with valid last name
        $results = $this->service->findPotentialDuplicates(['first_name' => '', 'last_name' => 'Doe']);
        expect($results['customers'])->toHaveCount(0);

        // Null last name with valid first name
        $results = $this->service->findPotentialDuplicates(['first_name' => 'John', 'last_name' => null]);
        expect($results['customers'])->toHaveCount(0);
    });

    it('excludes a specific customer ID from results', function () {
        $self = Customer::factory()->create(['email' => 'john@example.com']);
        $other = Customer::factory()->create(['email' => 'john@example.com']);

        $results = $this->service->findPotentialDuplicates(
            ['email' => 'john@example.com'],
            $self->id
        );

        expect($results['customers'])->toHaveCount(1);
        expect($results['customers']->first()['customer']->id)->toBe($other->id);
    });

    it('finds staff matches for agents and crew by email', function () {
        $agent = User::factory()->agent()->create(['email' => 'agent@example.com']);
        $crew = User::factory()->crew()->create(['email' => 'crew@example.com']);

        $results = $this->service->findPotentialDuplicates(['email' => 'agent@example.com']);
        expect($results['staff'])->toHaveCount(1);
        expect($results['staff']->first()['user']->id)->toBe($agent->id);
        expect($results['staff']->first()['match_type'])->toBe('email');

        $results = $this->service->findPotentialDuplicates(['email' => 'crew@example.com']);
        expect($results['staff'])->toHaveCount(1);
        expect($results['staff']->first()['user']->id)->toBe($crew->id);
    });

    it('does not flag internal users as staff matches', function () {
        User::factory()->internal()->create(['email' => 'internal@example.com']);

        $results = $this->service->findPotentialDuplicates(['email' => 'internal@example.com']);

        expect($results['staff'])->toHaveCount(0);
    });

    it('deduplicates same customer matched by multiple fields', function () {
        $existing = Customer::factory()->create([
            'email' => 'john@example.com',
            'phone' => '555-123-4567',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        $results = $this->service->findPotentialDuplicates([
            'email' => 'john@example.com',
            'phone' => '(555) 123-4567',
            'first_name' => 'john',
            'last_name' => 'doe',
        ]);

        // Same customer matched by email, phone, and name - should be deduplicated to 1
        $customerIds = $results['customers']->pluck('customer.id')->unique();
        expect($customerIds)->toHaveCount(1);
        expect($customerIds->first())->toBe($existing->id);
    });
});

describe('checkAndFlagDuplicates', function () {
    it('creates flag records for each match', function () {
        $existing = Customer::factory()->create(['email' => 'john@example.com']);
        $newCustomer = Customer::factory()->create(['email' => 'john@example.com']);

        $this->service->checkAndFlagDuplicates($newCustomer);

        $flags = CustomerDuplicateFlag::where('customer_id', $newCustomer->id)->get();
        expect($flags)->toHaveCount(1);
        expect($flags->first()->duplicate_customer_id)->toBe($existing->id);
        expect($flags->first()->match_type)->toBe('email');
        expect($flags->first()->status)->toBe('pending');
    });

    it('creates staff match flag with duplicate_user_id', function () {
        $agent = User::factory()->agent()->create(['email' => 'agent@example.com']);
        $customer = Customer::factory()->create(['email' => 'agent@example.com']);

        $this->service->checkAndFlagDuplicates($customer);

        $staffFlag = CustomerDuplicateFlag::query()
            ->where('customer_id', $customer->id)
            ->whereNotNull('duplicate_user_id')
            ->first();

        expect($staffFlag)->not->toBeNull();
        expect($staffFlag->duplicate_user_id)->toBe($agent->id);
        expect($staffFlag->duplicate_customer_id)->toBeNull();
        expect($staffFlag->match_type)->toBe('email');
    });

    it('does not re-flag dismissed duplicates', function () {
        $existing = Customer::factory()->create(['email' => 'john@example.com']);
        $newCustomer = Customer::factory()->create(['email' => 'john@example.com']);

        // Create a dismissed flag for this pair
        CustomerDuplicateFlag::factory()->dismissed()->create([
            'customer_id' => $newCustomer->id,
            'duplicate_customer_id' => $existing->id,
            'match_type' => 'email',
        ]);

        $this->service->checkAndFlagDuplicates($newCustomer);

        // Should still only have the one dismissed flag, no new pending flag
        $flags = CustomerDuplicateFlag::where('customer_id', $newCustomer->id)
            ->where('duplicate_customer_id', $existing->id)
            ->where('match_type', 'email')
            ->get();

        expect($flags)->toHaveCount(1);
        expect($flags->first()->status)->toBe('dismissed');
    });
});

describe('dismiss', function () {
    it('marks flag as dismissed with resolver and timestamp', function () {
        $flag = CustomerDuplicateFlag::factory()->create(['status' => 'pending']);
        $resolver = User::factory()->internal()->create();

        $this->service->dismiss($flag, $resolver);

        $flag->refresh();
        expect($flag->status)->toBe('dismissed');
        expect($flag->resolved_by)->toBe($resolver->id);
        expect($flag->resolved_at)->not->toBeNull();
        expect($flag->resolved_at->isToday())->toBeTrue();
    });
});

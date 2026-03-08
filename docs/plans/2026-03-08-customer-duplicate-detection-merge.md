# Customer Duplicate Detection & Merge — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Detect potential duplicate customers at creation time across all 8 entry points, alert internal users with a red dot in the comms hub and a banner on the customer profile, and provide a side-by-side merge page for manual resolution.

**Architecture:** A `CustomerDuplicateService` checks for matches (email, phone, name) whenever a customer is created and stores flags in a `customer_duplicate_flags` table. A `CustomerMergeService` handles reassigning all related records (deals, estimates, conversations, properties, activities) from the absorbed customer to the primary, then soft-deletes the absorbed record. UI changes are minimal — a red dot badge in the comms hub sidebar, a callout banner on the customer profile, and a new merge page component.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Pest 4, Flux UI, Tailwind v4

---

## Task 1: Migration — `customer_duplicate_flags` Table

**Files:**
- Create: `database/migrations/xxxx_create_customer_duplicate_flags_table.php`

**Step 1: Create migration**

```bash
php artisan make:migration create_customer_duplicate_flags_table --no-interaction
```

**Step 2: Write migration**

```php
Schema::create('customer_duplicate_flags', function (Blueprint $table) {
    $table->id();
    $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
    $table->foreignId('duplicate_customer_id')->nullable()->constrained('customers')->nullOnDelete();
    $table->foreignId('duplicate_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->string('match_type'); // email, phone, name
    $table->string('status')->default('pending'); // pending, merged, dismissed
    $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
    $table->timestamp('resolved_at')->nullable();
    $table->timestamps();

    $table->index(['customer_id', 'status']);
    $table->index(['duplicate_customer_id', 'status']);
});
```

Down migration: `Schema::dropIfExists('customer_duplicate_flags');`

**Step 3: Run migration**

```bash
php artisan migrate --force
```

**Step 4: Commit**

```bash
git add database/migrations/*customer_duplicate_flags*
git commit -m "feat: add customer_duplicate_flags table"
```

---

## Task 2: Model — `CustomerDuplicateFlag`

**Files:**
- Create: `app/Models/CustomerDuplicateFlag.php`
- Create: `database/factories/CustomerDuplicateFlagFactory.php`

**Step 1: Create model with factory**

```bash
php artisan make:model CustomerDuplicateFlag -f --no-interaction
```

**Step 2: Write the model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDuplicateFlag extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'duplicate_customer_id',
        'duplicate_user_id',
        'match_type',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function duplicateCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'duplicate_customer_id');
    }

    public function duplicateUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'duplicate_user_id');
    }

    public function resolvedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function isStaffMatch(): bool
    {
        return $this->duplicate_user_id !== null;
    }
}
```

**Step 3: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerDuplicateFlag;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerDuplicateFlagFactory extends Factory
{
    protected $model = CustomerDuplicateFlag::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'duplicate_customer_id' => Customer::factory(),
            'duplicate_user_id' => null,
            'match_type' => fake()->randomElement(['email', 'phone', 'name']),
            'status' => 'pending',
            'resolved_by' => null,
            'resolved_at' => null,
        ];
    }

    public function dismissed(): static
    {
        return $this->state([
            'status' => 'dismissed',
            'resolved_at' => now(),
        ]);
    }

    public function merged(): static
    {
        return $this->state([
            'status' => 'merged',
            'resolved_at' => now(),
        ]);
    }

    public function staffMatch(int $userId): static
    {
        return $this->state([
            'duplicate_customer_id' => null,
            'duplicate_user_id' => $userId,
        ]);
    }
}
```

**Step 4: Add `duplicateFlags` relationship to Customer model**

In `app/Models/Customer.php`, add after the existing relationships:

```php
public function duplicateFlags(): HasMany
{
    return $this->hasMany(CustomerDuplicateFlag::class);
}

public function pendingDuplicateFlags(): HasMany
{
    return $this->hasMany(CustomerDuplicateFlag::class)->where('status', 'pending');
}

public function hasPendingDuplicates(): bool
{
    return $this->pendingDuplicateFlags()->exists();
}
```

Add `use Illuminate\Database\Eloquent\Relations\HasMany;` import if not already present.

**Step 5: Commit**

```bash
git add app/Models/CustomerDuplicateFlag.php database/factories/CustomerDuplicateFlagFactory.php app/Models/Customer.php
git commit -m "feat: add CustomerDuplicateFlag model with factory and customer relationship"
```

---

## Task 3: `CustomerDuplicateService` — Detection Logic

**Files:**
- Create: `app/Services/CustomerDuplicateService.php`
- Create: `tests/Feature/CustomerDuplicateServiceTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/CustomerDuplicateServiceTest.php`:

```php
<?php

use App\Models\Customer;
use App\Models\CustomerDuplicateFlag;
use App\Models\User;
use App\Services\CustomerDuplicateService;

describe('findPotentialDuplicates', function () {
    it('finds duplicates by exact email match', function () {
        $existing = Customer::factory()->create(['email' => 'john@example.com']);

        $service = app(CustomerDuplicateService::class);
        $matches = $service->findPotentialDuplicates([
            'email' => 'john@example.com',
            'first_name' => 'Different',
            'last_name' => 'Person',
        ]);

        expect($matches['customers'])->toHaveCount(1);
        expect($matches['customers']->first()['customer']->id)->toBe($existing->id);
        expect($matches['customers']->first()['match_type'])->toBe('email');
    });

    it('finds duplicates by email case-insensitively', function () {
        $existing = Customer::factory()->create(['email' => 'John@Example.COM']);

        $service = app(CustomerDuplicateService::class);
        $matches = $service->findPotentialDuplicates([
            'email' => 'john@example.com',
        ]);

        expect($matches['customers'])->toHaveCount(1);
    });

    it('finds duplicates by normalized phone match', function () {
        $existing = Customer::factory()->create(['phone' => '(555) 123-4567']);

        $service = app(CustomerDuplicateService::class);
        $matches = $service->findPotentialDuplicates([
            'phone' => '555-123-4567',
            'first_name' => 'Someone',
            'last_name' => 'Else',
        ]);

        expect($matches['customers'])->toHaveCount(1);
        expect($matches['customers']->first()['match_type'])->toBe('phone');
    });

    it('finds duplicates by exact first and last name', function () {
        $existing = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'different@email.com',
        ]);

        $service = app(CustomerDuplicateService::class);
        $matches = $service->findPotentialDuplicates([
            'first_name' => 'john',
            'last_name' => 'smith',
            'email' => 'other@email.com',
        ]);

        expect($matches['customers'])->toHaveCount(1);
        expect($matches['customers']->first()['match_type'])->toBe('name');
    });

    it('does not match on first name alone', function () {
        Customer::factory()->create(['first_name' => 'John', 'last_name' => 'Smith']);

        $service = app(CustomerDuplicateService::class);
        $matches = $service->findPotentialDuplicates([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);

        expect($matches['customers'])->toBeEmpty();
    });

    it('does not match empty or null fields', function () {
        Customer::factory()->create(['email' => null, 'phone' => null]);

        $service = app(CustomerDuplicateService::class);
        $matches = $service->findPotentialDuplicates([
            'email' => null,
            'phone' => null,
            'first_name' => '',
        ]);

        expect($matches['customers'])->toBeEmpty();
    });

    it('excludes a specific customer ID from results', function () {
        $existing = Customer::factory()->create(['email' => 'john@example.com']);

        $service = app(CustomerDuplicateService::class);
        $matches = $service->findPotentialDuplicates(
            ['email' => 'john@example.com'],
            excludeCustomerId: $existing->id
        );

        expect($matches['customers'])->toBeEmpty();
    });

    it('finds staff matches for agents and crew', function () {
        $agent = User::factory()->agent()->create([
            'name' => 'Jane Agent',
            'email' => 'jane@agency.com',
        ]);

        $service = app(CustomerDuplicateService::class);
        $matches = $service->findPotentialDuplicates([
            'email' => 'jane@agency.com',
            'first_name' => 'Jane',
        ]);

        expect($matches['staff'])->toHaveCount(1);
        expect($matches['staff']->first()['user']->id)->toBe($agent->id);
    });

    it('does not flag internal users as staff matches', function () {
        User::factory()->internal()->create(['email' => 'admin@company.com']);

        $service = app(CustomerDuplicateService::class);
        $matches = $service->findPotentialDuplicates([
            'email' => 'admin@company.com',
        ]);

        expect($matches['staff'])->toBeEmpty();
    });

    it('deduplicates results when matching by multiple criteria', function () {
        $existing = Customer::factory()->create([
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'john@example.com',
            'phone' => '555-1234',
        ]);

        $service = app(CustomerDuplicateService::class);
        $matches = $service->findPotentialDuplicates([
            'first_name' => 'John',
            'last_name' => 'Smith',
            'email' => 'john@example.com',
            'phone' => '555-1234',
        ]);

        // Same customer matched by email, phone, and name — but only one entry per customer
        // Each match type should be listed though
        $customerIds = $matches['customers']->pluck('customer.id')->unique();
        expect($customerIds)->toHaveCount(1);
    });
});

describe('flagDuplicates', function () {
    it('creates flag records for each match', function () {
        $existing = Customer::factory()->create(['email' => 'john@example.com']);
        $new = Customer::factory()->create(['email' => 'john@example.com']);

        $service = app(CustomerDuplicateService::class);
        $service->checkAndFlagDuplicates($new);

        expect(CustomerDuplicateFlag::where('customer_id', $new->id)->count())->toBe(1);

        $flag = CustomerDuplicateFlag::where('customer_id', $new->id)->first();
        expect($flag->duplicate_customer_id)->toBe($existing->id);
        expect($flag->match_type)->toBe('email');
        expect($flag->status)->toBe('pending');
    });

    it('creates staff match flag with duplicate_user_id', function () {
        $agent = User::factory()->agent()->create(['email' => 'jane@agency.com']);
        $customer = Customer::factory()->create(['email' => 'jane@agency.com']);

        $service = app(CustomerDuplicateService::class);
        $service->checkAndFlagDuplicates($customer);

        $flag = CustomerDuplicateFlag::where('customer_id', $customer->id)
            ->whereNotNull('duplicate_user_id')
            ->first();

        expect($flag)->not->toBeNull();
        expect($flag->duplicate_user_id)->toBe($agent->id);
    });

    it('does not re-flag dismissed duplicates', function () {
        $existing = Customer::factory()->create(['email' => 'john@example.com']);
        $new = Customer::factory()->create(['email' => 'john@example.com']);

        // First flag, then dismiss
        CustomerDuplicateFlag::factory()->dismissed()->create([
            'customer_id' => $new->id,
            'duplicate_customer_id' => $existing->id,
            'match_type' => 'email',
        ]);

        $service = app(CustomerDuplicateService::class);
        $service->checkAndFlagDuplicates($new);

        // Should still be just the one dismissed flag, no new pending flag
        expect(CustomerDuplicateFlag::where('customer_id', $new->id)->where('status', 'pending')->count())->toBe(0);
    });
});

describe('dismiss', function () {
    it('marks flag as dismissed', function () {
        $flag = CustomerDuplicateFlag::factory()->create();
        $user = User::factory()->internal()->create();

        $service = app(CustomerDuplicateService::class);
        $service->dismiss($flag, $user);

        $flag->refresh();
        expect($flag->status)->toBe('dismissed');
        expect($flag->resolved_by)->toBe($user->id);
        expect($flag->resolved_at)->not->toBeNull();
    });
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/CustomerDuplicateServiceTest.php
```

Expected: FAIL — `CustomerDuplicateService` class not found.

**Step 3: Write the service**

Create `app/Services/CustomerDuplicateService.php`:

```php
<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerDuplicateFlag;
use App\Models\User;
use Illuminate\Support\Collection;

class CustomerDuplicateService
{
    /**
     * Find potential duplicate customers and staff matching the given data.
     *
     * @return array{customers: Collection, staff: Collection}
     */
    public function findPotentialDuplicates(array $customerData, ?int $excludeCustomerId = null): array
    {
        $customerMatches = collect();
        $staffMatches = collect();

        $email = $customerData['email'] ?? null;
        $phone = $customerData['phone'] ?? null;
        $firstName = $customerData['first_name'] ?? null;
        $lastName = $customerData['last_name'] ?? null;

        // Email match
        if ($email && trim($email) !== '') {
            Customer::query()
                ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
                ->when($excludeCustomerId, fn ($q) => $q->where('id', '!=', $excludeCustomerId))
                ->get()
                ->each(function (Customer $c) use (&$customerMatches) {
                    $customerMatches->push(['customer' => $c, 'match_type' => 'email']);
                });

            // Staff match by email (agents, crew only)
            User::query()
                ->whereIn('type', ['agent', 'crew'])
                ->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])
                ->get()
                ->each(function (User $u) use (&$staffMatches) {
                    $staffMatches->push(['user' => $u, 'match_type' => 'email']);
                });
        }

        // Phone match (normalized — digits only)
        if ($phone && trim($phone) !== '') {
            $normalizedPhone = preg_replace('/\D/', '', $phone);

            if (strlen($normalizedPhone) >= 7) {
                Customer::query()
                    ->whereNotNull('phone')
                    ->where('phone', '!=', '')
                    ->when($excludeCustomerId, fn ($q) => $q->where('id', '!=', $excludeCustomerId))
                    ->get()
                    ->filter(fn (Customer $c) => preg_replace('/\D/', '', $c->phone) === $normalizedPhone)
                    ->each(function (Customer $c) use (&$customerMatches) {
                        // Don't add if already matched by email
                        if (! $customerMatches->contains(fn ($m) => $m['customer']->id === $c->id && $m['match_type'] === 'phone')) {
                            $customerMatches->push(['customer' => $c, 'match_type' => 'phone']);
                        }
                    });
            }
        }

        // Name match (case-insensitive, both first AND last must match)
        if ($firstName && trim($firstName) !== '' && $lastName && trim($lastName) !== '') {
            Customer::query()
                ->whereRaw('LOWER(first_name) = ?', [strtolower(trim($firstName))])
                ->whereRaw('LOWER(last_name) = ?', [strtolower(trim($lastName))])
                ->when($excludeCustomerId, fn ($q) => $q->where('id', '!=', $excludeCustomerId))
                ->get()
                ->each(function (Customer $c) use (&$customerMatches) {
                    if (! $customerMatches->contains(fn ($m) => $m['customer']->id === $c->id && $m['match_type'] === 'name')) {
                        $customerMatches->push(['customer' => $c, 'match_type' => 'name']);
                    }
                });
        }

        return [
            'customers' => $customerMatches,
            'staff' => $staffMatches,
        ];
    }

    /**
     * Check for duplicates and create flag records for a newly created customer.
     */
    public function checkAndFlagDuplicates(Customer $customer): void
    {
        $matches = $this->findPotentialDuplicates([
            'email' => $customer->email,
            'phone' => $customer->phone,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
        ], excludeCustomerId: $customer->id);

        // Flag customer matches
        foreach ($matches['customers'] as $match) {
            $this->createFlagIfNotDismissed(
                $customer->id,
                $match['match_type'],
                duplicateCustomerId: $match['customer']->id,
            );
        }

        // Flag staff matches
        foreach ($matches['staff'] as $match) {
            $this->createFlagIfNotDismissed(
                $customer->id,
                $match['match_type'],
                duplicateUserId: $match['user']->id,
            );
        }
    }

    /**
     * Dismiss a duplicate flag.
     */
    public function dismiss(CustomerDuplicateFlag $flag, User $resolvedBy): void
    {
        $flag->update([
            'status' => 'dismissed',
            'resolved_by' => $resolvedBy->id,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Only create a flag if no dismissed flag already exists for this pair+type.
     */
    private function createFlagIfNotDismissed(
        int $customerId,
        string $matchType,
        ?int $duplicateCustomerId = null,
        ?int $duplicateUserId = null,
    ): void {
        $exists = CustomerDuplicateFlag::query()
            ->where('customer_id', $customerId)
            ->where('match_type', $matchType)
            ->when($duplicateCustomerId, fn ($q) => $q->where('duplicate_customer_id', $duplicateCustomerId))
            ->when($duplicateUserId, fn ($q) => $q->where('duplicate_user_id', $duplicateUserId))
            ->exists();

        if ($exists) {
            return;
        }

        CustomerDuplicateFlag::create([
            'customer_id' => $customerId,
            'duplicate_customer_id' => $duplicateCustomerId,
            'duplicate_user_id' => $duplicateUserId,
            'match_type' => $matchType,
            'status' => 'pending',
        ]);
    }
}
```

**Step 4: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/CustomerDuplicateServiceTest.php
```

Expected: All PASS.

**Step 5: Commit**

```bash
git add app/Services/CustomerDuplicateService.php tests/Feature/CustomerDuplicateServiceTest.php
git commit -m "feat: add CustomerDuplicateService with detection and flagging"
```

---

## Task 4: `CustomerMergeService` — Merge Logic

**Files:**
- Create: `app/Services/CustomerMergeService.php`
- Create: `tests/Feature/CustomerMergeServiceTest.php`

**Step 1: Write the failing tests**

Create `tests/Feature/CustomerMergeServiceTest.php`:

```php
<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\CustomerDuplicateFlag;
use App\Models\Deal;
use App\Models\MessengerConversation;
use App\Models\Property;
use App\Models\User;
use App\Services\CustomerMergeService;

describe('merge', function () {
    it('reassigns deals from absorbed to primary customer', function () {
        $primary = Customer::factory()->create();
        $absorbed = Customer::factory()->create();
        $deal = Deal::factory()->create(['customer_id' => $absorbed->id]);

        $user = User::factory()->internal()->create();
        $service = app(CustomerMergeService::class);
        $service->merge($primary, $absorbed, [], $user);

        $deal->refresh();
        expect($deal->customer_id)->toBe($primary->id);
    });

    it('reassigns properties from absorbed to primary customer', function () {
        $primary = Customer::factory()->create();
        $absorbed = Customer::factory()->create();
        $property = Property::factory()->create(['customer_id' => $absorbed->id]);

        $user = User::factory()->internal()->create();
        $service = app(CustomerMergeService::class);
        $service->merge($primary, $absorbed, [], $user);

        $property->refresh();
        expect($property->customer_id)->toBe($primary->id);
    });

    it('applies field selections to primary customer', function () {
        $primary = Customer::factory()->create([
            'phone' => '111-1111',
            'email' => 'primary@test.com',
        ]);
        $absorbed = Customer::factory()->create([
            'phone' => '222-2222',
            'email' => 'absorbed@test.com',
        ]);

        $user = User::factory()->internal()->create();
        $service = app(CustomerMergeService::class);
        $service->merge($primary, $absorbed, [
            'phone' => $absorbed->phone,
            'email' => $primary->email,
        ], $user);

        $primary->refresh();
        expect($primary->phone)->toBe('222-2222');
        expect($primary->email)->toBe('primary@test.com');
    });

    it('soft-deletes the absorbed customer', function () {
        $primary = Customer::factory()->create();
        $absorbed = Customer::factory()->create();

        $user = User::factory()->internal()->create();
        $service = app(CustomerMergeService::class);
        $service->merge($primary, $absorbed, [], $user);

        expect($absorbed->fresh())->toBeNull();
        expect(Customer::withTrashed()->find($absorbed->id))->not->toBeNull();
    });

    it('marks all related duplicate flags as merged', function () {
        $primary = Customer::factory()->create();
        $absorbed = Customer::factory()->create();

        $flag = CustomerDuplicateFlag::factory()->create([
            'customer_id' => $primary->id,
            'duplicate_customer_id' => $absorbed->id,
        ]);

        $user = User::factory()->internal()->create();
        $service = app(CustomerMergeService::class);
        $service->merge($primary, $absorbed, [], $user);

        $flag->refresh();
        expect($flag->status)->toBe('merged');
        expect($flag->resolved_by)->toBe($user->id);
    });

    it('creates an activity log entry for the merge', function () {
        $primary = Customer::factory()->create();
        $absorbed = Customer::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

        $user = User::factory()->internal()->create();
        $service = app(CustomerMergeService::class);
        $service->merge($primary, $absorbed, [], $user);

        $activity = Activity::where('subject_type', Customer::class)
            ->where('subject_id', $primary->id)
            ->where('type', 'customer_merged')
            ->first();

        expect($activity)->not->toBeNull();
        expect($activity->description)->toContain('Jane Doe');
    });

    it('reassigns messenger conversations', function () {
        $primary = Customer::factory()->create();
        $absorbed = Customer::factory()->create();

        $conversation = MessengerConversation::factory()->create([
            'customer_id' => $absorbed->id,
        ]);

        $user = User::factory()->internal()->create();
        $service = app(CustomerMergeService::class);
        $service->merge($primary, $absorbed, [], $user);

        $conversation->refresh();
        expect($conversation->customer_id)->toBe($primary->id);
    });

    it('handles case where absorbed customer has portal user and primary does not', function () {
        $primary = Customer::factory()->create(['user_id' => null]);
        $portalUser = User::factory()->customer()->create();
        $absorbed = Customer::factory()->create(['user_id' => $portalUser->id]);

        $user = User::factory()->internal()->create();
        $service = app(CustomerMergeService::class);
        $service->merge($primary, $absorbed, [], $user);

        $primary->refresh();
        expect($primary->user_id)->toBe($portalUser->id);
    });

    it('deactivates absorbed portal user when both customers have portal access', function () {
        $primaryUser = User::factory()->customer()->create();
        $absorbedUser = User::factory()->customer()->create();

        $primary = Customer::factory()->create(['user_id' => $primaryUser->id]);
        $absorbed = Customer::factory()->create(['user_id' => $absorbedUser->id]);

        $user = User::factory()->internal()->create();
        $service = app(CustomerMergeService::class);
        $service->merge($primary, $absorbed, [], $user);

        $primary->refresh();
        expect($primary->user_id)->toBe($primaryUser->id);
        // Absorbed user should still exist but customer is soft-deleted
    });

    it('provides merge summary with counts', function () {
        $primary = Customer::factory()->create();
        $absorbed = Customer::factory()->create();
        Deal::factory()->count(2)->create(['customer_id' => $absorbed->id]);
        Property::factory()->create(['customer_id' => $absorbed->id]);

        $service = app(CustomerMergeService::class);
        $summary = $service->getMergeSummary($primary, $absorbed);

        expect($summary['deals'])->toBe(2);
        expect($summary['properties'])->toBe(1);
    });
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/CustomerMergeServiceTest.php
```

Expected: FAIL — `CustomerMergeService` class not found.

**Step 3: Write the service**

Create `app/Services/CustomerMergeService.php`:

```php
<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\CustomerDuplicateFlag;
use App\Models\Deal;
use App\Models\MessengerConversation;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CustomerMergeService
{
    /**
     * Merge the absorbed customer into the primary customer.
     *
     * @param  array<string, mixed>  $fieldSelections  Field values chosen from side-by-side picker
     */
    public function merge(Customer $primary, Customer $absorbed, array $fieldSelections, User $resolvedBy): Customer
    {
        return DB::transaction(function () use ($primary, $absorbed, $fieldSelections, $resolvedBy) {
            // 1. Apply field selections
            $mergeable = ['first_name', 'last_name', 'email', 'phone', 'address', 'city', 'state', 'zip', 'notes', 'source', 'lead_source_id'];
            $updates = [];
            foreach ($mergeable as $field) {
                if (isset($fieldSelections[$field])) {
                    $updates[$field] = $fieldSelections[$field];
                }
            }
            if (! empty($updates)) {
                $primary->update($updates);
            }

            // 2. Reassign related records
            Deal::where('customer_id', $absorbed->id)->update(['customer_id' => $primary->id]);
            Property::where('customer_id', $absorbed->id)->update(['customer_id' => $primary->id]);
            MessengerConversation::where('customer_id', $absorbed->id)->update(['customer_id' => $primary->id]);
            ChatThread::where('customer_id', $absorbed->id)->update(['customer_id' => $primary->id]);

            // Activities (polymorphic)
            Activity::where('subject_type', Customer::class)
                ->where('subject_id', $absorbed->id)
                ->update(['subject_id' => $primary->id]);

            // 3. Transfer portal user if primary doesn't have one
            if (! $primary->user_id && $absorbed->user_id) {
                $primary->update(['user_id' => $absorbed->user_id]);
            }

            // 4. Mark all duplicate flags between these two as merged
            CustomerDuplicateFlag::query()
                ->where(function ($q) use ($primary, $absorbed) {
                    $q->where(function ($q2) use ($primary, $absorbed) {
                        $q2->where('customer_id', $primary->id)
                            ->where('duplicate_customer_id', $absorbed->id);
                    })->orWhere(function ($q2) use ($primary, $absorbed) {
                        $q2->where('customer_id', $absorbed->id)
                            ->where('duplicate_customer_id', $primary->id);
                    });
                })
                ->update([
                    'status' => 'merged',
                    'resolved_by' => $resolvedBy->id,
                    'resolved_at' => now(),
                ]);

            // 5. Reassign any other pending flags from absorbed → primary
            CustomerDuplicateFlag::where('customer_id', $absorbed->id)
                ->where('status', 'pending')
                ->update(['customer_id' => $primary->id]);

            // 6. Log the merge
            Activity::create([
                'subject_type' => Customer::class,
                'subject_id' => $primary->id,
                'user_id' => $resolvedBy->id,
                'type' => 'customer_merged',
                'description' => "Merged customer {$absorbed->full_name} (ID: {$absorbed->id}) into this record.",
            ]);

            // 7. Soft-delete absorbed customer
            $absorbed->delete();

            return $primary->fresh();
        });
    }

    /**
     * Get a summary of what will be reassigned during a merge.
     *
     * @return array{deals: int, properties: int, conversations: int, activities: int}
     */
    public function getMergeSummary(Customer $primary, Customer $absorbed): array
    {
        return [
            'deals' => Deal::where('customer_id', $absorbed->id)->count(),
            'properties' => Property::where('customer_id', $absorbed->id)->count(),
            'conversations' => MessengerConversation::where('customer_id', $absorbed->id)->count(),
            'activities' => Activity::where('subject_type', Customer::class)
                ->where('subject_id', $absorbed->id)->count(),
        ];
    }
}
```

**Step 4: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/CustomerMergeServiceTest.php
```

Expected: All PASS.

**Step 5: Commit**

```bash
git add app/Services/CustomerMergeService.php tests/Feature/CustomerMergeServiceTest.php
git commit -m "feat: add CustomerMergeService for manual customer merging"
```

---

## Task 5: Integrate Detection Into All 8 Creation Paths

**Files:**
- Modify: `app/Services/MessengerLeadCreationService.php` (lines 42-63 and 162-180)
- Modify: `app/Services/ReferralService.php` (after customer creation ~line 58)
- Modify: `app/Services/DoorKnockService.php` (after customer creation ~line 93)
- Modify: `app/Services/AppointmentBookingService.php` (after customer creation ~line 72)
- Modify: `app/Services/WebhookLeadProcessingService.php` (after customer creation ~line 103)
- Modify: `app/Services/InvitationService.php` (after customer linking ~line 254)
- Modify: `app/Automation/Actions/CreateCustomer.php` (after customer creation ~line 154)
- Modify: `resources/views/components/customers/⚡create-modal.blade.php` (after customer creation)
- Create: `tests/Feature/CustomerDuplicateFlaggingIntegrationTest.php`

**Step 1: Write integration test**

Create `tests/Feature/CustomerDuplicateFlaggingIntegrationTest.php`:

```php
<?php

use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\CustomerDuplicateFlag;
use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Services\MessengerBotFlowService;
use App\Services\MessengerLeadCreationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

it('flags duplicate when messenger bot creates a matching customer', function () {
    Notification::fake();

    $existing = Customer::factory()->create(['email' => 'john@test.com']);

    MessengerSetting::clearInstanceCache();
    MessengerSetting::getInstance()->update([
        'verify_token' => 'test',
        'app_secret' => 'test',
        'page_access_token' => 'test',
        'page_id' => '123',
    ]);
    MessengerSetting::clearInstanceCache();

    Http::fake(['graph.facebook.com/*' => Http::response(['message_id' => 'mid.1'])]);

    $flow = MessengerBotFlow::factory()->active()->create();
    $step = MessengerBotStep::factory()->textQuestion('customer.email')->create([
        'flow_id' => $flow->id,
        'sort_order' => 1,
        'message_text' => 'Email?',
    ]);

    $thread = ChatThread::factory()->facebook('flag-test')->create();
    $conversation = MessengerConversation::create([
        'flow_id' => $flow->id,
        'thread_id' => $thread->id,
        'facebook_psid' => 'flag-test',
        'current_step_id' => $step->id,
        'status' => 'in_progress',
        'answers' => ["step_{$step->id}" => 'john@test.com'],
        'started_at' => now(),
        'last_activity_at' => now(),
    ]);

    $service = app(MessengerLeadCreationService::class);
    $result = $service->createFromConversation($conversation);

    // MessengerLeadCreation uses findExistingCustomer, so it reuses existing.
    // But if it creates new, it should flag.
    // Test the case where name matches but email doesn't:
    $existing2 = Customer::factory()->create([
        'first_name' => 'Mike',
        'last_name' => 'Jones',
        'email' => 'other@test.com',
    ]);

    $conversation2 = MessengerConversation::create([
        'flow_id' => $flow->id,
        'thread_id' => ChatThread::factory()->facebook('flag-test-2')->create()->id,
        'facebook_psid' => 'flag-test-2',
        'current_step_id' => $step->id,
        'status' => 'in_progress',
        'answers' => ["step_{$step->id}" => 'mike@different.com'],
        'fb_profile_data' => ['first_name' => 'Mike', 'last_name' => 'Jones'],
        'started_at' => now(),
        'last_activity_at' => now(),
    ]);

    $result2 = $service->createFromConversation($conversation2);

    if ($result2['is_new_customer']) {
        $flags = CustomerDuplicateFlag::where('customer_id', $result2['customer']->id)->get();
        expect($flags)->not->toBeEmpty();
        expect($flags->first()->match_type)->toBe('name');
    }
});

it('flags duplicate when referral form creates a customer matching existing', function () {
    $existing = Customer::factory()->create(['phone' => '555-123-4567']);

    $service = app(\App\Services\ReferralService::class);
    $result = $service->createFromLeadForm([
        'first_name' => 'New',
        'last_name' => 'Person',
        'email' => 'new@test.com',
        'phone' => '(555) 123-4567',
        'address' => '123 Main St',
        'city' => 'Denver',
        'state' => 'CO',
        'zip' => '80202',
    ]);

    $flags = CustomerDuplicateFlag::where('customer_id', $result['customer']->id)->get();
    expect($flags)->not->toBeEmpty();
    expect($flags->first()->match_type)->toBe('phone');
});
```

**Step 2: Add `checkAndFlagDuplicates` call to each creation path**

In each service, after the customer is created (or found as new), add:

```php
app(CustomerDuplicateService::class)->checkAndFlagDuplicates($customer);
```

**Integration points** (add the one-liner after customer creation at each location):

1. **`MessengerLeadCreationService::createFromConversation()`** — after line 62 (after `Customer::create`)
2. **`MessengerLeadCreationService::createFromManualConversion()`** — after line 180 (after `Customer::create`)
3. **`ReferralService::createFromLeadForm()`** — after customer creation ~line 58
4. **`DoorKnockService::convertToLead()`** — after `Customer::firstOrCreate` ~line 93
5. **`AppointmentBookingService::bookAppointment()`** — after `Customer::firstOrCreate` ~line 72
6. **`WebhookLeadProcessingService::findOrCreateCustomer()`** — after `Customer::create` ~line 103
7. **`InvitationService::linkCustomerToUser()`** — after `Customer::create` ~line 254
8. **`Automation\Actions\CreateCustomer::execute()`** — after `Customer::create` ~line 154
9. **`⚡create-modal.blade.php`** — after `Customer::create` in the `save()` method

Each file also needs: `use App\Services\CustomerDuplicateService;`

**Step 3: Fix phone query performance in `MessengerLeadCreationService::findExistingCustomer()`**

Replace lines 289-298 (the phone matching block that loads all customers):

```php
// Try phone match (normalized — query-based)
if (! empty($customerData['phone'])) {
    $normalizedPhone = $this->normalizePhone($customerData['phone']);
    if ($normalizedPhone) {
        $customers = Customer::whereNotNull('phone')->where('phone', '!=', '')->get();
        $customer = $customers->first(fn ($c) => $this->normalizePhone($c->phone ?? '') === $normalizedPhone);
        if ($customer) {
            return $customer;
        }
    }
}
```

Note: The phone normalization still needs to iterate because phone formats vary in the DB. However, this can be improved later with a `normalized_phone` column. For now, the existing approach works for the current customer volume. The key fix is that `checkAndFlagDuplicates` uses the same normalization approach.

**Step 4: Run integration tests**

```bash
php artisan test --compact tests/Feature/CustomerDuplicateFlaggingIntegrationTest.php
```

**Step 5: Run all affected test suites to check for regressions**

```bash
php artisan test --compact tests/Feature/Messenger/
php artisan test --compact --filter=Referral
php artisan test --compact --filter=DoorKnock
php artisan test --compact --filter=Webhook
php artisan test --compact --filter=Invitation
```

**Step 6: Commit**

```bash
git add app/Services/ app/Automation/Actions/CreateCustomer.php resources/views/components/customers/⚡create-modal.blade.php tests/Feature/CustomerDuplicateFlaggingIntegrationTest.php
git commit -m "feat: integrate duplicate detection into all 8 customer creation paths"
```

---

## Task 6: Comms Hub — Red Dot Indicator

**Files:**
- Modify: `app/Services/CommunicationsContactService.php` (~line 199, contact array building)
- Modify: `resources/views/components/communications/⚡hub.blade.php` (~line 770, red dot area)

**Step 1: Add `has_duplicate_flag` to contact data**

In `CommunicationsContactService.php`, where the contact array is built (~line 195-207), add a `has_duplicate_flag` key. Eager-load pending duplicate flags for customer-type contacts:

```php
'has_duplicate_flag' => $user->type === 'customer'
    && $user->customer
    && $user->customer->pendingDuplicateFlags()->exists(),
```

Note: Check how `$user->customer` is resolved — it may be via `Customer::where('user_id', $user->id)`. If so, add the relationship or use a query.

For messenger contacts (~line 320-332), check if the messenger conversation's `customer_id` links to a customer with pending flags:

```php
'has_duplicate_flag' => $thread->customer_id
    && CustomerDuplicateFlag::where('customer_id', $thread->customer_id)->where('status', 'pending')->exists(),
```

**Step 2: Add duplicate dot to hub template**

In `⚡hub.blade.php`, after the existing unread red dot (line 770), add a duplicate indicator. Place an orange/amber dot next to the avatar when `has_duplicate_flag` is true:

```blade
@if ($contact['has_duplicate_flag'] ?? false)
    <span class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 bg-amber-500 rounded-full border-2 border-white dark:border-zinc-900" title="{{ __('Possible duplicate') }}"></span>
@endif
```

Use amber (not red) to differentiate from the unread indicator. Position at bottom-right vs top-right for unread.

**Step 3: Commit**

```bash
git add app/Services/CommunicationsContactService.php resources/views/components/communications/⚡hub.blade.php
git commit -m "feat: add duplicate flag indicator (amber dot) in communications hub"
```

---

## Task 7: Customer Profile — Duplicate Banner

**Files:**
- Modify: `resources/views/components/customers/⚡show.blade.php`

**Step 1: Add computed property for pending flags**

In the PHP section of `⚡show.blade.php`, add:

```php
#[Computed]
public function pendingDuplicateFlags(): Collection
{
    return $this->customer->pendingDuplicateFlags()
        ->with(['duplicateCustomer', 'duplicateUser'])
        ->get();
}

public function dismissDuplicateFlag(int $flagId): void
{
    $flag = CustomerDuplicateFlag::findOrFail($flagId);
    app(CustomerDuplicateService::class)->dismiss($flag, auth()->user());
    unset($this->pendingDuplicateFlags);
}
```

Add necessary imports: `CustomerDuplicateFlag`, `CustomerDuplicateService`, `Collection`.

**Step 2: Add banner in template**

In the template, right after the heading/breadcrumb area (~line 694, after the `<flux:heading>` line), add:

```blade
@foreach ($this->pendingDuplicateFlags as $flag)
    <div wire:key="dup-flag-{{ $flag->id }}" class="rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-900/20">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2 text-sm text-amber-800 dark:text-amber-200">
                <flux:icon name="exclamation-triangle" class="w-5 h-5 shrink-0" />
                @if ($flag->isStaffMatch())
                    {{ __('Possible staff match: This customer may be existing :type :name.', [
                        'type' => $flag->duplicateUser->type,
                        'name' => $flag->duplicateUser->name,
                    ]) }}
                @else
                    {{ __('Possible duplicate: Matches :name (:match).', [
                        'name' => $flag->duplicateCustomer->full_name,
                        'match' => $flag->match_type,
                    ]) }}
                @endif
            </div>
            <div class="flex items-center gap-2 shrink-0">
                @if (! $flag->isStaffMatch())
                    <flux:button size="sm" variant="primary"
                        :href="route('customers.merge', ['customer' => $this->customer->id, 'duplicate' => $flag->duplicate_customer_id])"
                        wire:navigate>
                        {{ __('Review & Merge') }}
                    </flux:button>
                @endif
                <flux:button size="sm" variant="ghost" wire:click="dismissDuplicateFlag({{ $flag->id }})">
                    {{ __('Dismiss') }}
                </flux:button>
            </div>
        </div>
    </div>
@endforeach
```

**Step 3: Commit**

```bash
git add resources/views/components/customers/⚡show.blade.php
git commit -m "feat: add duplicate detection banner on customer profile page"
```

---

## Task 8: Merge Page — Side-by-Side Comparison

**Files:**
- Create: `resources/views/components/customers/⚡merge.blade.php`
- Modify: `routes/web.php` (add merge route)

**Step 1: Add route**

In `routes/web.php`, in the customer routes section (~line 313), add:

```php
Route::livewire('/customers/{customer}/merge/{duplicate}', 'customers.merge')->name('customers.merge');
```

**Step 2: Create merge component**

Create `resources/views/components/customers/⚡merge.blade.php`:

```blade
<?php

use App\Models\Customer;
use App\Models\User;
use App\Services\CustomerMergeService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public function boot(): void
    {
        if (! auth()->user()?->can('people.view')) {
            abort(403);
        }
    }

    public Customer $customer;
    public Customer $duplicate;
    public array $fieldSelections = [];
    public bool $showConfirmation = false;

    public function mount(Customer $customer, Customer $duplicate): void
    {
        $this->customer = $customer;
        $this->duplicate = $duplicate;

        // Pre-select: prefer non-empty values, default to primary (customer)
        foreach ($this->mergeableFields() as $field => $label) {
            $primaryVal = $this->customer->{$field};
            $duplicateVal = $this->duplicate->{$field};

            if (! empty($primaryVal)) {
                $this->fieldSelections[$field] = 'primary';
            } elseif (! empty($duplicateVal)) {
                $this->fieldSelections[$field] = 'duplicate';
            } else {
                $this->fieldSelections[$field] = 'primary';
            }
        }
    }

    public function mergeableFields(): array
    {
        return [
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'address' => 'Address',
            'city' => 'City',
            'state' => 'State',
            'zip' => 'ZIP',
            'notes' => 'Notes',
            'source' => 'Source',
        ];
    }

    #[Computed]
    public function mergeSummary(): array
    {
        return app(CustomerMergeService::class)->getMergeSummary($this->customer, $this->duplicate);
    }

    public function confirmMerge(): void
    {
        $this->showConfirmation = true;
    }

    public function executeMerge(): void
    {
        $resolvedFields = [];
        foreach ($this->mergeableFields() as $field => $label) {
            $selection = $this->fieldSelections[$field] ?? 'primary';
            $resolvedFields[$field] = $selection === 'duplicate'
                ? $this->duplicate->{$field}
                : $this->customer->{$field};
        }

        app(CustomerMergeService::class)->merge(
            $this->customer,
            $this->duplicate,
            $resolvedFields,
            auth()->user()
        );

        $this->redirect(route('customers.show', $this->customer), navigate: true);
        session()->flash('notify', __('Customers merged successfully.'));
    }
};
?>

<div class="max-w-4xl mx-auto space-y-6">
    <div class="flex items-center gap-3 mb-4">
        <flux:button variant="ghost" :href="route('customers.show', $customer)" icon="arrow-left" wire:navigate size="sm" />
        <flux:heading size="xl">{{ __('Merge Customers') }}</flux:heading>
    </div>

    {{-- Side-by-side comparison --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900">
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 w-1/5">{{ __('Field') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 w-2/5">
                        {{ __('Primary: :name', ['name' => $customer->full_name]) }}
                        <flux:badge size="sm" color="green" class="ml-1">{{ __('Keep') }}</flux:badge>
                    </th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 w-2/5">
                        {{ __('Duplicate: :name', ['name' => $duplicate->full_name]) }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->mergeableFields() as $field => $label)
                    @php
                        $primaryVal = $customer->{$field} ?? '';
                        $duplicateVal = $duplicate->{$field} ?? '';
                        $conflict = $primaryVal !== $duplicateVal && $primaryVal !== '' && $duplicateVal !== '';
                    @endphp
                    <tr class="{{ $conflict ? 'bg-amber-50/50 dark:bg-amber-900/10' : '' }}">
                        <td class="px-4 py-3 font-medium text-zinc-700 dark:text-zinc-300">{{ __($label) }}</td>
                        <td class="px-4 py-3">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="fieldSelections.{{ $field }}" value="primary"
                                    class="text-blue-600 focus:ring-blue-500" />
                                <span class="{{ $primaryVal ? 'text-zinc-900 dark:text-white' : 'text-zinc-400 italic' }}">
                                    {{ $primaryVal ?: __('(empty)') }}
                                </span>
                            </label>
                        </td>
                        <td class="px-4 py-3">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="fieldSelections.{{ $field }}" value="duplicate"
                                    class="text-blue-600 focus:ring-blue-500" />
                                <span class="{{ $duplicateVal ? 'text-zinc-900 dark:text-white' : 'text-zinc-400 italic' }}">
                                    {{ $duplicateVal ?: __('(empty)') }}
                                </span>
                            </label>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Merge summary --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="sm" class="mb-2">{{ __('Records to reassign') }}</flux:heading>
        <div class="flex gap-6 text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ __(':count deals', ['count' => $this->mergeSummary['deals']]) }}</span>
            <span>{{ __(':count properties', ['count' => $this->mergeSummary['properties']]) }}</span>
            <span>{{ __(':count conversations', ['count' => $this->mergeSummary['conversations']]) }}</span>
            <span>{{ __(':count activities', ['count' => $this->mergeSummary['activities']]) }}</span>
        </div>
        <flux:text class="text-xs text-zinc-500 mt-1">
            {{ __('These records will be moved from the duplicate to the primary customer. The duplicate will be soft-deleted.') }}
        </flux:text>
    </div>

    {{-- Action buttons --}}
    <div class="flex justify-end gap-3">
        <flux:button variant="ghost" :href="route('customers.show', $customer)" wire:navigate>
            {{ __('Cancel') }}
        </flux:button>

        @if (! $showConfirmation)
            <flux:button variant="danger" wire:click="confirmMerge">
                {{ __('Merge Customers') }}
            </flux:button>
        @else
            <div class="flex items-center gap-3 rounded-lg border border-red-300 bg-red-50 px-4 py-2 dark:border-red-700 dark:bg-red-900/20">
                <flux:text class="text-sm text-red-700 dark:text-red-300">{{ __('This cannot be undone. Confirm?') }}</flux:text>
                <flux:button variant="danger" wire:click="executeMerge">
                    {{ __('Yes, Merge') }}
                </flux:button>
                <flux:button variant="ghost" wire:click="$set('showConfirmation', false)" size="sm">
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        @endif
    </div>
</div>
```

**Step 3: Commit**

```bash
git add resources/views/components/customers/⚡merge.blade.php routes/web.php
git commit -m "feat: add side-by-side customer merge page"
```

---

## Task 9: Notification Enhancement

**Files:**
- Modify: `app/Notifications/NewMessengerLead.php` (~line 68, email content)

**Step 1: Add duplicate flag info to notification**

In `NewMessengerLead.php`, in the `toMail()` method after the customer/deal lines, add:

```php
$pendingFlags = $this->customer->pendingDuplicateFlags()
    ->with('duplicateCustomer')
    ->get();

if ($pendingFlags->isNotEmpty()) {
    $matchNames = $pendingFlags
        ->filter(fn ($f) => $f->duplicateCustomer)
        ->map(fn ($f) => $f->duplicateCustomer->full_name)
        ->unique()
        ->implode(', ');

    if ($matchNames) {
        $message->line("⚠ Possible duplicate of: {$matchNames}");
    }
}
```

Similarly, in the `toArray()` method for database notifications, add a `'has_duplicate'` key and `'duplicate_names'` string to the data array.

**Step 2: Commit**

```bash
git add app/Notifications/NewMessengerLead.php
git commit -m "feat: add duplicate warning to new lead notifications"
```

---

## Task 10: Final Tests, Pint, and Verification

**Files:**
- All modified files

**Step 1: Run Pint**

```bash
vendor/bin/pint --dirty
```

**Step 2: Run all new tests**

```bash
php artisan test --compact tests/Feature/CustomerDuplicateServiceTest.php
php artisan test --compact tests/Feature/CustomerMergeServiceTest.php
php artisan test --compact tests/Feature/CustomerDuplicateFlaggingIntegrationTest.php
```

**Step 3: Run regression tests on affected areas**

```bash
php artisan test --compact tests/Feature/Messenger/
php artisan test --compact --filter=Customer
php artisan test --compact --filter=Referral
php artisan test --compact --filter=DoorKnock
php artisan test --compact --filter=Webhook
php artisan test --compact --filter=Invitation
```

**Step 4: Ask user if they want full test suite**

```bash
php artisan test --compact
```

**Step 5: Final commit**

```bash
git add -A
git commit -m "feat: customer duplicate detection and merge system"
```

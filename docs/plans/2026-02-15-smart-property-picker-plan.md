# Smart Property Picker Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** When customers reach Step 2 of onboarding and already have properties on file, show selectable property cards instead of a blank address form. Includes an "Add a New Property" card that reveals the form.

**Architecture:** Single-file change to the onboarding SFC component. Add two public properties (`selectedPropertyId`, `showNewPropertyForm`), one computed (`existingProperties`), one action method (`selectProperty`). Replace Step 2 Blade template with conditional: cards when properties exist, form when none or "Add New" clicked.

**Tech Stack:** Livewire 4 SFC, Flux UI, Pest 4

**Design Doc:** `docs/plans/2026-02-15-smart-property-picker-design.md`

---

### Task 1: Write Tests for Property Picker Behavior

**Files:**
- Create: `tests/Feature/Customer/OnboardingPropertyPickerTest.php`

**Step 1: Create test file with all test cases**

```bash
php artisan make:test Customer/OnboardingPropertyPickerTest --pest
```

Write these tests in `tests/Feature/Customer/OnboardingPropertyPickerTest.php`:

```php
<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->internal = User::factory()->internal()->withFullAccess()->create();
});

describe('Property picker display', function () {
    it('shows property cards when customer has existing properties', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);
        $property = Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
            'type' => 'residential',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->assertSee('123 Main St')
            ->assertSee('Denver, CO 80202')
            ->assertSee('Add a New Property');
    });

    it('shows multiple property cards for customer with multiple properties', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);
        Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
        ]);
        Property::factory()->forCustomer($customer)->create([
            'address' => '456 Oak Ave',
            'city' => 'Boulder',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->assertSee('123 Main St')
            ->assertSee('456 Oak Ave');
    });

    it('shows blank form when customer has no properties', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->assertDontSee('Add a New Property')
            ->assertSee('Property Details');
    });
});

describe('Selecting existing property', function () {
    it('can select an existing property and advance to step 3', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);
        $property = Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->call('selectProperty', $property->id)
            ->assertSet('selectedPropertyId', $property->id)
            ->assertSet('step', 3);
    });

    it('uses selected property for deal on completion', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);
        $property = Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('selectProperty', $property->id)
            ->assertSet('step', 3)
            ->call('nextStep')
            ->assertSet('step', 4)
            ->call('selectEstimatePreference', 'virtual')
            ->assertRedirect(route('customer.dashboard'));

        $deal = $customer->deals()->first();
        expect($deal)->not->toBeNull();
        expect($deal->property_id)->toBe($property->id);
    });

    it('rejects selecting a property that does not belong to customer', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
        $otherProperty = Property::factory()->create(); // belongs to another customer

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('selectProperty', $otherProperty->id)
            ->assertSet('selectedPropertyId', null)
            ->assertSet('step', 2);
    });
});

describe('Adding new property via form', function () {
    it('can toggle new property form and hide cards', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);
        Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->assertSet('step', 2)
            ->assertSet('showNewPropertyForm', false)
            ->call('showAddPropertyForm')
            ->assertSet('showNewPropertyForm', true);
    });

    it('creates new property when form is submitted', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);
        Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('showAddPropertyForm')
            ->set('address', '789 New Rd')
            ->set('city', 'Aurora')
            ->set('state', 'CO')
            ->set('zip', '80010')
            ->call('nextStep')
            ->assertSet('step', 3)
            ->call('nextStep')
            ->call('selectEstimatePreference', 'virtual')
            ->assertRedirect(route('customer.dashboard'));

        expect($customer->properties()->count())->toBe(2);
        expect($customer->properties()->where('address', '789 New Rd')->exists())->toBeTrue();
    });

    it('can cancel new property form and return to cards', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => true]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
            'address' => null,
            'city' => null,
        ]);
        Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding')
            ->call('nextStep')
            ->call('showAddPropertyForm')
            ->assertSet('showNewPropertyForm', true)
            ->call('cancelAddPropertyForm')
            ->assertSet('showNewPropertyForm', false)
            ->assertSee('123 Main St');
    });
});

describe('New project flow with property picker', function () {
    it('shows property picker for returning customer requesting new project', function () {
        $user = User::factory()->customer()->create(['needs_onboarding' => false]);
        $customer = Customer::factory()->create([
            'user_id' => $user->id,
            'email' => $user->email,
        ]);
        $property = Property::factory()->forCustomer($customer)->create([
            'address' => '123 Main St',
            'city' => 'Denver',
        ]);

        Livewire::actingAs($user, 'customer')
            ->test('customer.onboarding', ['new_project' => true])
            ->assertSet('step', 2)
            ->assertSee('123 Main St')
            ->assertSee('Add a New Property');
    });
});
```

**Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Customer/OnboardingPropertyPickerTest.php
```

Expected: All tests FAIL (methods `selectProperty`, `showAddPropertyForm`, `cancelAddPropertyForm` don't exist yet, property cards not rendered).

**Step 3: Commit failing tests**

```bash
git add tests/Feature/Customer/OnboardingPropertyPickerTest.php
git commit -m "test: add failing tests for smart property picker in onboarding"
```

---

### Task 2: Add PHP Properties and Methods to Onboarding Component

**Files:**
- Modify: `resources/views/components/customer/⚡onboarding.blade.php` (PHP section only, lines 1-368)

**Step 1: Add new public properties after line 37**

After `public string $propertyType = 'residential';` (line 37), add:

```php
    // Step 2: Property picker
    public ?int $selectedPropertyId = null;

    public bool $showNewPropertyForm = false;
```

**Step 2: Add computed property for existing properties**

After the `customer()` computed (line 51), add:

```php
    #[Computed]
    public function existingProperties(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->customer) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return $this->customer->properties()->latest()->get();
    }
```

**Step 3: Add selectProperty action method**

After `previousStep()` (line 173), add:

```php
    public function selectProperty(int $propertyId): void
    {
        $customer = $this->customer;

        if (! $customer) {
            return;
        }

        // Verify the property belongs to this customer
        $property = $customer->properties()->find($propertyId);

        if (! $property) {
            return;
        }

        $this->selectedPropertyId = $propertyId;
        $this->step = min($this->step + 1, 4);
    }

    public function showAddPropertyForm(): void
    {
        $this->showNewPropertyForm = true;
        $this->selectedPropertyId = null;
    }

    public function cancelAddPropertyForm(): void
    {
        $this->showNewPropertyForm = false;
    }
```

**Step 4: Update complete() to use selectedPropertyId**

In the `complete()` method, replace the property creation block (lines 249-269) with logic that handles both selected and new properties:

Replace:
```php
            // Create property if address provided and no existing property matches
            $property = null;
            if ($this->address && $this->city) {
                $existingProperty = $customer->properties()
                    ->where('address', $this->address)
                    ->where('city', $this->city)
                    ->first();

                if (! $existingProperty) {
                    $property = Property::create([
                        'customer_id' => $customer->id,
                        'address' => $this->address,
                        'city' => $this->city,
                        'state' => $this->state ?: '',
                        'zip' => $this->zip ?: '',
                        'type' => $this->propertyType,
                    ]);
                } else {
                    $property = $existingProperty;
                }
            }
```

With:
```php
            // Resolve property: use selected existing property or create new one
            $property = null;
            if ($this->selectedPropertyId) {
                $property = $customer->properties()->find($this->selectedPropertyId);
            } elseif ($this->address && $this->city) {
                $existingProperty = $customer->properties()
                    ->where('address', $this->address)
                    ->where('city', $this->city)
                    ->first();

                if (! $existingProperty) {
                    $property = Property::create([
                        'customer_id' => $customer->id,
                        'address' => $this->address,
                        'city' => $this->city,
                        'state' => $this->state ?: '',
                        'zip' => $this->zip ?: '',
                        'type' => $this->propertyType,
                    ]);
                } else {
                    $property = $existingProperty;
                }
            }
```

**Step 5: Update createDealAndEstimate() to accept property parameter**

The `createDealAndEstimate()` method (line 333) currently finds the property from `$customer->properties()->latest()->first()`. Update it to accept an optional property parameter so it uses the selected property:

Change signature from:
```php
    private function createDealAndEstimate(Customer $customer): void
```
To:
```php
    private function createDealAndEstimate(Customer $customer, ?Property $property = null): void
```

And update the Deal creation (line 347) from:
```php
                'property_id' => $customer->properties()->latest()->first()?->id,
```
To:
```php
                'property_id' => $property?->id ?? $customer->properties()->latest()->first()?->id,
```

**Step 6: Update the call to createDealAndEstimate in complete()**

In `complete()`, change (line 274):
```php
                $this->createDealAndEstimate($customer);
```
To:
```php
                $this->createDealAndEstimate($customer, $property);
```

**Step 7: Run tests**

```bash
php artisan test --compact tests/Feature/Customer/OnboardingPropertyPickerTest.php
```

Expected: Tests that check PHP behavior (selectProperty, showAddPropertyForm, cancelAddPropertyForm) may pass. Tests that check `assertSee` for card text will still fail since Blade template hasn't changed yet.

**Step 8: Commit**

```bash
git add resources/views/components/customer/⚡onboarding.blade.php
git commit -m "feat: add property picker state and methods to onboarding component"
```

---

### Task 3: Update Step 2 Blade Template with Property Cards

**Files:**
- Modify: `resources/views/components/customer/⚡onboarding.blade.php` (Blade section, lines 488-535)

**Step 1: Replace Step 2 template block**

Replace the entire Step 2 block (lines 488-535, from `{{-- Step 2: Property --}}` to the closing `@endif`) with:

```blade
        {{-- Step 2: Property --}}
        @if ($step === 2)
            <flux:heading size="lg" class="mb-1">{{ __('Property Details') }}</flux:heading>
            <flux:text size="sm" class="text-zinc-500 mb-6">{{ __('Where is the project located?') }}</flux:text>

            @if ($this->existingProperties->isNotEmpty() && ! $showNewPropertyForm)
                {{-- Property cards --}}
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ($this->existingProperties as $prop)
                        <button
                            wire:key="property-{{ $prop->id }}"
                            wire:click="selectProperty({{ $prop->id }})"
                            class="flex flex-col items-start gap-1 rounded-lg border-2 p-4 text-left transition-all hover:border-blue-500 hover:shadow-md border-zinc-200 dark:border-zinc-700"
                        >
                            <div class="flex items-center gap-2">
                                <flux:icon name="home" class="h-5 w-5 text-zinc-400" />
                                <p class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $prop->address }}</p>
                            </div>
                            <p class="text-sm text-zinc-500">{{ $prop->city }}{{ $prop->state ? ', ' . $prop->state : '' }}{{ $prop->zip ? ' ' . $prop->zip : '' }}</p>
                            <flux:badge size="sm" class="mt-1">{{ ucfirst($prop->type) }}</flux:badge>
                        </button>
                    @endforeach

                    <button
                        wire:click="showAddPropertyForm"
                        class="flex flex-col items-center justify-center gap-2 rounded-lg border-2 border-dashed p-4 text-center transition-all hover:border-blue-500 hover:bg-zinc-50 dark:hover:bg-zinc-800/50 border-zinc-300 dark:border-zinc-600"
                    >
                        <flux:icon name="plus" class="h-8 w-8 text-zinc-400" />
                        <p class="font-medium text-zinc-600 dark:text-zinc-400">{{ __('Add a New Property') }}</p>
                    </button>
                </div>

                <div class="mt-6 flex justify-between">
                    <flux:button variant="ghost" wire:click="previousStep">{{ __('Back') }}</flux:button>
                </div>
            @else
                {{-- New property form (shown when no properties exist or "Add New" clicked) --}}
                <div class="space-y-4">
                    <flux:field>
                        <flux:label>{{ __('Address') }}</flux:label>
                        <flux:input wire:model="address" placeholder="123 Main St" />
                        <flux:error name="address" />
                    </flux:field>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <flux:field>
                            <flux:label>{{ __('City') }}</flux:label>
                            <flux:input wire:model="city" placeholder="Denver" />
                            <flux:error name="city" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('State') }}</flux:label>
                            <flux:input wire:model="state" placeholder="CO" />
                            <flux:error name="state" />
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('ZIP') }}</flux:label>
                            <flux:input wire:model="zip" placeholder="80202" />
                            <flux:error name="zip" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Property Type') }}</flux:label>
                        <flux:select wire:model="propertyType">
                            <option value="residential">{{ __('Residential') }}</option>
                            <option value="commercial">{{ __('Commercial') }}</option>
                            <option value="industrial">{{ __('Industrial') }}</option>
                        </flux:select>
                        <flux:error name="propertyType" />
                    </flux:field>
                </div>

                <div class="mt-6 flex justify-between">
                    @if ($this->existingProperties->isNotEmpty())
                        <flux:button variant="ghost" wire:click="cancelAddPropertyForm">{{ __('Back to Properties') }}</flux:button>
                    @else
                        <flux:button variant="ghost" wire:click="previousStep">{{ __('Back') }}</flux:button>
                    @endif
                    <flux:button variant="primary" wire:click="nextStep">{{ __('Continue') }}</flux:button>
                </div>
            @endif
        @endif
```

**Step 2: Run tests**

```bash
php artisan test --compact tests/Feature/Customer/OnboardingPropertyPickerTest.php
```

Expected: All tests PASS.

**Step 3: Run existing onboarding tests to ensure no regressions**

```bash
php artisan test --compact tests/Feature/CrmOverhaulTest.php --filter="onboarding"
```

Expected: All existing onboarding tests still pass. The "can complete onboarding flow" test creates a customer with no properties, so it goes through the blank form path unchanged.

**Step 4: Run pint**

```bash
vendor/bin/pint --dirty
```

**Step 5: Commit**

```bash
git add resources/views/components/customer/⚡onboarding.blade.php
git commit -m "feat: smart property picker cards in customer onboarding Step 2"
```

---

### Task 4: Verify All Tests Pass and Final Review

**Step 1: Run the full property picker test suite**

```bash
php artisan test --compact tests/Feature/Customer/OnboardingPropertyPickerTest.php
```

**Step 2: Run all existing onboarding-related tests**

```bash
php artisan test --compact tests/Feature/CrmOverhaulTest.php --filter="onboarding|skip|preference|deal|estimate"
php artisan test --compact tests/Feature/Customer/AgentSourcedOnboardingTest.php
```

**Step 3: Ask user if they want to run the full suite**

Prompt user: "Property picker tests and existing onboarding tests pass. Want me to run the full test suite?"

**Step 4: Commit any pint fixes**

```bash
vendor/bin/pint --dirty
git add -A && git commit -m "style: pint formatting" || true
```

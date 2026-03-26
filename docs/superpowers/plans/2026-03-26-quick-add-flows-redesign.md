# Quick-Add Flows Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix agent/crew quick-add forms to properly send invitations + SMS, add two-tier agent form with deal/estimate creation, and replace customer invite emails with preview page links.

**Architecture:** Modify `InvitationService` to support pre-existing users via `existing_user_id` on invitations. Fix quick-add components to create Users correctly (mass-assignable fields only). Add `CustomerPreviewNotification` for preview-link emails. Add SMS dispatch for agent/crew invites.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Pest 4, TwilioService, InvitationService

**Spec:** `docs/superpowers/specs/2026-03-26-quick-add-flows-redesign-design.md`

---

### Task 1: Migration — add `existing_user_id` to invitations table

**Files:**
- Create: `database/migrations/2026_03_26_000001_add_existing_user_id_to_invitations_table.php`
- Modify: `app/Models/Invitation.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration add_existing_user_id_to_invitations_table --no-interaction
```

Replace content with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->foreignId('existing_user_id')->nullable()->after('inviter_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('existing_user_id');
        });
    }
};
```

- [ ] **Step 2: Add `existing_user_id` to Invitation model fillable**

In `app/Models/Invitation.php`, add `'existing_user_id'` to the `$fillable` array (after `'inviter_id'`).

- [ ] **Step 3: Add `existingUser` relationship to Invitation model**

```php
public function existingUser(): BelongsTo
{
    return $this->belongsTo(User::class, 'existing_user_id');
}
```

- [ ] **Step 4: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 5: Commit**

```bash
git add database/migrations/*existing_user_id* app/Models/Invitation.php
git commit -m "feat: add existing_user_id to invitations for pre-created users"
```

---

### Task 2: Modify InvitationService — support pre-existing users

**Files:**
- Modify: `app/Services/InvitationService.php:62-98` (sendInvitation), `app/Services/InvitationService.php:169-237` (acceptInvitation)
- Test: `tests/Feature/InvitationSystemTest.php`

- [ ] **Step 1: Write failing test — sendInvitation with existingUserId bypasses user check**

Add to `tests/Feature/InvitationSystemTest.php`:

```php
it('sends invitation for pre-existing user when existingUserId is provided', function () {
    $inviter = User::factory()->internal()->create();
    $agent = User::factory()->agent()->create(['email' => 'agent@test.com']);

    $invitationService = app(InvitationService::class);

    $invitation = $invitationService->sendInvitation(
        inviter: $inviter,
        email: 'agent@test.com',
        invitedUserType: 'agent',
        existingUserId: $agent->id,
    );

    expect($invitation)->toBeInstanceOf(\App\Models\Invitation::class)
        ->and($invitation->existing_user_id)->toBe($agent->id)
        ->and($invitation->email)->toBe('agent@test.com');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter="sends invitation for pre-existing user"
```

Expected: FAIL — `sendInvitation()` throws "A user with this email already exists as an agent."

- [ ] **Step 3: Modify `sendInvitation()` to accept `existingUserId`**

In `app/Services/InvitationService.php`, update the method signature and add bypass logic:

```php
public function sendInvitation(User $inviter, string $email, string $invitedUserType, ?int $serviceCategoryId = null, ?int $dealId = null, ?int $existingUserId = null): Invitation
```

Update the pending invitation re-send block (lines 77-85) to set `existing_user_id` if provided:

```php
if ($existingInvitation) {
    // Update existing_user_id if this is a quick-add re-invite
    if ($existingUserId && ! $existingInvitation->existing_user_id) {
        $existingInvitation->update(['existing_user_id' => $existingUserId]);
    }

    // Resend the existing invitation to the invitee's email
    $notification = $invitedUserType === 'agent'
        ? new AgentInvitationNotification($existingInvitation)
        : new InvitationNotification($existingInvitation);
    $existingInvitation->notify($notification);

    return $existingInvitation;
}
```

Replace the existing-user check block (lines 87-98) with:

```php
// Check if user already exists with this email (unique constraint on email)
$existingUser = User::where('email', $email)->first();

if ($existingUser && ! $existingUserId) {
    $existingType = $existingUser->type;

    throw new \InvalidArgumentException(
        $existingType === $invitedUserType
            ? "A user with this email already exists as a {$invitedUserType}."
            : "A user with this email already exists as a {$existingType}. Each email can only have one account."
    );
}
```

Add `existing_user_id` to `$invitationData` (after `'inviter_id'`):

```php
if ($existingUserId) {
    $invitationData['existing_user_id'] = $existingUserId;
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
php artisan test --compact --filter="sends invitation for pre-existing user"
```

- [ ] **Step 5: Write failing test — acceptInvitation updates existing user instead of creating**

```php
it('accepts invitation for pre-existing user by updating password and name', function () {
    $inviter = User::factory()->internal()->create();
    $agent = User::factory()->agent()->create([
        'email' => 'preagent@test.com',
        'needs_onboarding' => false,
    ]);
    $agent->agentProfile->update(['company_name' => 'Test Brokerage']);

    $invitation = \App\Models\Invitation::create([
        'email' => 'preagent@test.com',
        'invited_user_type' => 'agent',
        'inviter_id' => $inviter->id,
        'existing_user_id' => $agent->id,
    ]);

    $invitationService = app(InvitationService::class);
    $user = $invitationService->acceptInvitation($invitation, [
        'name' => 'New Agent Name',
        'password' => 'password123',
    ]);

    expect($user->id)->toBe($agent->id)
        ->and($user->name)->toBe('New Agent Name')
        ->and($user->needs_onboarding)->toBeFalse()
        ->and(Hash::check('password123', $user->password))->toBeTrue()
        ->and($user->agentProfile->company_name)->toBe('Test Brokerage');

    expect($invitation->fresh()->accepted_at)->not->toBeNull();
});
```

Add `use Illuminate\Support\Facades\Hash;` to the top of the test file if not present.

- [ ] **Step 6: Run test to verify it fails**

```bash
php artisan test --compact --filter="accepts invitation for pre-existing user"
```

Expected: FAIL — throws "A user with this email already exists."

- [ ] **Step 7: Modify `acceptInvitation()` to handle pre-existing users**

In `app/Services/InvitationService.php`, replace the existing-user check and user creation block (lines 179-197) with:

```php
// Check if this invitation is for a pre-created user (from quick-add)
if ($invitation->existing_user_id) {
    $user = User::findOrFail($invitation->existing_user_id);
    $user->update([
        'name' => $userData['name'],
        'password' => bcrypt($userData['password']),
    ]);
    // Preserve existing needs_onboarding value — do NOT override
} else {
    // Check if user already exists with this email
    $existingUser = User::where('email', $invitation->email)->first();

    if ($existingUser) {
        throw new \InvalidArgumentException(
            'A user with this email already exists. Please log in with your existing account instead.'
        );
    }

    // Create the user
    $user = User::create([
        'name' => $userData['name'],
        'email' => $invitation->email,
        'password' => bcrypt($userData['password']),
        'needs_onboarding' => in_array($invitation->invited_user_type, ['customer', 'agent', 'crew'], true),
    ]);
    $user->type = $invitation->invited_user_type;
    $user->is_active = true;
    $user->save();
}
```

Then wrap the type-specific profile creation blocks so they only run for NEW users (not pre-existing). Add this condition around the customer, crew, and agent blocks:

```php
if (! $invitation->existing_user_id) {
    // Link or create Customer record for this user (for customer invitations)
    if ($invitation->invited_user_type === 'customer') {
        // ... existing customer code ...
    }

    // Create crew profile with service category (for crew invitations)
    if ($invitation->invited_user_type === 'crew') {
        $this->createCrewProfile($user, $invitation);
    }

    // Send welcome SMS to new agents
    if ($user->type === 'agent' && $user->phone) {
        \App\Jobs\SendWelcomeSmsJob::dispatch(
            $user->phone,
            null,
            $user->id,
        )->delay(now()->addSeconds(5));
    }
}
```

- [ ] **Step 8: Run both tests**

```bash
php artisan test --compact --filter="sends invitation for pre-existing|accepts invitation for pre-existing"
```

Expected: Both PASS

- [ ] **Step 9: Run full invitation test suite to check for regressions**

```bash
php artisan test --compact tests/Feature/InvitationSystemTest.php
```

Expected: All tests pass

- [ ] **Step 10: Run Pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 11: Commit**

```bash
git add app/Services/InvitationService.php tests/Feature/InvitationSystemTest.php
git commit -m "feat: InvitationService supports pre-existing users via existing_user_id"
```

---

### Task 3: Fix and enhance crew quick-add — proper User creation + SMS

**Files:**
- Modify: `resources/views/components/communications/⚡quick-add-crew.blade.php`
- Test: `tests/Feature/Communications/QuickAddCrewTest.php`

- [ ] **Step 1: Create test file**

```bash
php artisan make:test Communications/QuickAddCrewTest --pest --no-interaction
```

- [ ] **Step 2: Write failing tests**

Write to `tests/Feature/Communications/QuickAddCrewTest.php`:

```php
<?php

use App\Models\CrewProfile;
use App\Models\Invitation;
use App\Models\User;
use App\Services\TwilioService;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->internal()->create());
});

it('creates crew user with correct field mapping', function () {
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(false);
    app()->instance(TwilioService::class, $twilio);

    Livewire::test('communications.quick-add-crew')
        ->set('first_name', 'John')
        ->set('last_name', 'Painter')
        ->set('email', 'john@crew.com')
        ->set('phone', '3035551234')
        ->set('company_name', 'JP Painting')
        ->call('submitQuickAdd');

    $user = User::where('email', 'john@crew.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('John Painter')
        ->and($user->type)->toBe('crew')
        ->and($user->needs_onboarding)->toBeTrue();

    $profile = $user->crewProfile;
    expect($profile)->not->toBeNull()
        ->and($profile->company_name)->toBe('JP Painting')
        ->and($profile->phone)->toBe('3035551234');
});

it('creates invitation with existing_user_id for crew', function () {
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(false);
    app()->instance(TwilioService::class, $twilio);

    Livewire::test('communications.quick-add-crew')
        ->set('first_name', 'Jane')
        ->set('last_name', 'Crew')
        ->set('email', 'jane@crew.com')
        ->set('phone', '3035555678')
        ->set('company_name', 'JC Painting')
        ->call('submitQuickAdd');

    $user = User::where('email', 'jane@crew.com')->first();
    $invitation = Invitation::where('email', 'jane@crew.com')->first();

    expect($invitation)->not->toBeNull()
        ->and($invitation->existing_user_id)->toBe($user->id)
        ->and($invitation->invited_user_type)->toBe('crew');
});

it('sends SMS with invite link when phone is provided', function () {
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldReceive('sendSms')
        ->once()
        ->withArgs(function ($to, $body) {
            return str_contains($to, '3035559999') && str_contains($body, '/invitation/accept/');
        })
        ->andReturn(['success' => true, 'sid' => 'SM123']);
    app()->instance(TwilioService::class, $twilio);

    Livewire::test('communications.quick-add-crew')
        ->set('first_name', 'Sms')
        ->set('last_name', 'Test')
        ->set('email', 'sms@crew.com')
        ->set('phone', '3035559999')
        ->call('submitQuickAdd');
});

it('does not send SMS when phone is empty', function () {
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldNotReceive('sendSms');
    app()->instance(TwilioService::class, $twilio);

    Livewire::test('communications.quick-add-crew')
        ->set('first_name', 'No')
        ->set('last_name', 'Phone')
        ->set('email', 'nophone@crew.com')
        ->call('submitQuickAdd');
});

it('shows success modal after creation', function () {
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(false);
    app()->instance(TwilioService::class, $twilio);

    Livewire::test('communications.quick-add-crew')
        ->set('first_name', 'Success')
        ->set('last_name', 'Modal')
        ->set('email', 'success@crew.com')
        ->call('submitQuickAdd')
        ->assertSet('showSuccessModal', true)
        ->assertSet('showModal', false);
});
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Communications/QuickAddCrewTest.php
```

Expected: Tests fail (current component doesn't create User correctly, no SMS, no invitation with existingUserId)

- [ ] **Step 4: Rewrite `⚡quick-add-crew.blade.php` PHP section**

Replace the entire PHP section (`<?php ... ?>`) with:

```php
<?php

use App\Models\User;
use App\Services\InvitationService;
use App\Services\TwilioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $showModal = false;

    public bool $showSuccessModal = false;

    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $phone = '';

    public string $company_name = '';

    public ?int $createdCrewId = null;

    #[On('open-quick-add-crew')]
    public function openModal(): void
    {
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset([
            'first_name', 'last_name', 'email', 'phone',
            'company_name', 'createdCrewId',
        ]);
        $this->resetValidation();
    }

    public function submitQuickAdd(): void
    {
        $validated = $this->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'company_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = DB::transaction(function () use ($validated) {
            // Set type BEFORE save so boot hook creates CrewProfile
            $user = new User([
                'name' => trim($validated['first_name'].' '.$validated['last_name']),
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => bcrypt(str()->random(32)),
                'needs_onboarding' => true,
            ]);
            $user->type = 'crew';
            $user->is_active = true;
            $user->save(); // created hook fires with type='crew' → CrewProfile auto-created

            // Update the auto-created CrewProfile with form data
            $user->crewProfile->update([
                'company_name' => $validated['company_name'] ?? null,
                'phone' => $validated['phone'] ?? null,
            ]);

            return $user;
        });

        // Send invitation email (with existingUserId to bypass user-exists check)
        $invitation = null;
        try {
            $invitationService = app(InvitationService::class);
            $invitation = $invitationService->sendInvitation(
                inviter: auth()->user(),
                email: $validated['email'],
                invitedUserType: 'crew',
                existingUserId: $user->id,
            );
        } catch (\InvalidArgumentException $e) {
            Log::warning('Quick-add crew: invitation failed', ['error' => $e->getMessage()]);
        }

        // Send SMS with invite link
        if ($invitation && ! empty($validated['phone'])) {
            try {
                $twilio = app(TwilioService::class);
                if ($twilio->isEnabled()) {
                    $invitationService = app(InvitationService::class);
                    $acceptUrl = $invitationService->generateAcceptUrl($invitation);
                    $companyName = \App\Models\CompanySetting::getInstance()->company_name ?? config('app.name');

                    $twilio->sendSms(
                        $validated['phone'],
                        __("Hi :name! You've been invited to join :company. Set up your account here: :link", [
                            'name' => $validated['first_name'],
                            'company' => $companyName,
                            'link' => $acceptUrl,
                        ]),
                    );
                }
            } catch (\Exception $e) {
                Log::warning('Quick-add crew: SMS failed', ['error' => $e->getMessage()]);
            }
        }

        $this->createdCrewId = $user->id;
        $this->showModal = false;
        $this->showSuccessModal = true;
    }

    public function goToCrew(): void
    {
        $this->redirect(route('crews.show', $this->createdCrewId));
    }

    public function stayOnHub(): void
    {
        $this->showSuccessModal = false;
        $this->dispatch('crew-quick-added');
        $this->reset([
            'first_name', 'last_name', 'email', 'phone',
            'company_name', 'createdCrewId',
        ]);
    }
};
?>
```

Keep the existing Blade template section unchanged.

- [ ] **Step 5: Run tests**

```bash
php artisan test --compact tests/Feature/Communications/QuickAddCrewTest.php
```

Expected: All PASS

- [ ] **Step 6: Run Pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/communications/⚡quick-add-crew.blade.php tests/Feature/Communications/QuickAddCrewTest.php
git commit -m "feat: crew quick-add sends invitation + SMS with invite link"
```

---

### Task 4: Fix and enhance agent quick-add — two-tier form with deal/estimate

**Files:**
- Modify: `resources/views/components/communications/⚡quick-add-agent.blade.php`
- Test: `tests/Feature/Communications/QuickAddAgentTest.php`

- [ ] **Step 1: Create test file**

```bash
php artisan make:test Communications/QuickAddAgentTest --pest --no-interaction
```

- [ ] **Step 2: Write failing tests**

Write to `tests/Feature/Communications/QuickAddAgentTest.php`:

```php
<?php

use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Invitation;
use App\Models\Property;
use App\Models\User;
use App\Services\TwilioService;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->internal()->create());

    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(false);
    app()->instance(TwilioService::class, $twilio);
});

it('creates agent with correct field mapping (tier 1 - top only)', function () {
    Livewire::test('communications.quick-add-agent')
        ->set('first_name', 'Jane')
        ->set('last_name', 'Realtor')
        ->set('email', 'jane@realty.com')
        ->set('phone', '3035551111')
        ->set('company_name', 'RE/MAX Denver')
        ->call('submitQuickAdd');

    $user = User::where('email', 'jane@realty.com')->first();

    expect($user)->not->toBeNull()
        ->and($user->name)->toBe('Jane Realtor')
        ->and($user->type)->toBe('agent')
        ->and($user->needs_onboarding)->toBeTrue();

    $profile = $user->agentProfile;
    expect($profile)->not->toBeNull()
        ->and($profile->company_name)->toBe('RE/MAX Denver')
        ->and($profile->phone)->toBe('3035551111');
});

it('creates invitation with existing_user_id for agent', function () {
    Livewire::test('communications.quick-add-agent')
        ->set('first_name', 'Invite')
        ->set('last_name', 'Agent')
        ->set('email', 'invite@agent.com')
        ->set('phone', '3035552222')
        ->call('submitQuickAdd');

    $user = User::where('email', 'invite@agent.com')->first();
    $invitation = Invitation::where('email', 'invite@agent.com')->first();

    expect($invitation)->not->toBeNull()
        ->and($invitation->existing_user_id)->toBe($user->id)
        ->and($invitation->invited_user_type)->toBe('agent');
});

it('creates property, deal, and estimate when full form is filled (tier 2)', function () {
    Livewire::test('communications.quick-add-agent')
        ->set('first_name', 'Full')
        ->set('last_name', 'Agent')
        ->set('email', 'full@agent.com')
        ->set('phone', '3035553333')
        ->set('company_name', 'Keller Williams')
        ->set('address', '123 Main St')
        ->set('city', 'Denver')
        ->set('state', 'CO')
        ->set('zip', '80202')
        ->set('listing_price', '450000')
        ->set('intent', 'selling')
        ->call('submitQuickAdd');

    $user = User::where('email', 'full@agent.com')->first();

    expect($user->needs_onboarding)->toBeFalse();

    $property = Property::where('address', '123 Main St')->first();
    expect($property)->not->toBeNull()
        ->and($property->city)->toBe('Denver')
        ->and($property->agent_id)->toBe($user->id);

    $deal = Deal::where('property_id', $property->id)->first();
    expect($deal)->not->toBeNull()
        ->and($deal->stage)->toBe('lead')
        ->and($deal->agent_id)->toBe($user->id);

    $estimate = Estimate::where('deal_id', $deal->id)->first();
    expect($estimate)->not->toBeNull()
        ->and($estimate->status)->toBe('draft');
});

it('sets needs_onboarding to true when only top fields filled (tier 1)', function () {
    Livewire::test('communications.quick-add-agent')
        ->set('first_name', 'Basic')
        ->set('last_name', 'Agent')
        ->set('email', 'basic@agent.com')
        ->call('submitQuickAdd');

    $user = User::where('email', 'basic@agent.com')->first();

    expect($user->needs_onboarding)->toBeTrue();
    expect(Property::count())->toBe(0);
    expect(Deal::where('agent_id', $user->id)->count())->toBe(0);
});

it('sends SMS with invite link when phone provided', function () {
    $twilio = Mockery::mock(TwilioService::class);
    $twilio->shouldReceive('isEnabled')->andReturn(true);
    $twilio->shouldReceive('sendSms')
        ->once()
        ->withArgs(fn ($to, $body) => str_contains($body, '/invitation/accept/'))
        ->andReturn(['success' => true, 'sid' => 'SM456']);
    app()->instance(TwilioService::class, $twilio);

    Livewire::test('communications.quick-add-agent')
        ->set('first_name', 'Sms')
        ->set('last_name', 'Agent')
        ->set('email', 'sms@agent.com')
        ->set('phone', '3035554444')
        ->call('submitQuickAdd');
});

it('shows success modal after creation', function () {
    Livewire::test('communications.quick-add-agent')
        ->set('first_name', 'Success')
        ->set('last_name', 'Agent')
        ->set('email', 'success@agent.com')
        ->call('submitQuickAdd')
        ->assertSet('showSuccessModal', true)
        ->assertSet('showModal', false);
});
```

- [ ] **Step 3: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Communications/QuickAddAgentTest.php
```

- [ ] **Step 4: Rewrite `⚡quick-add-agent.blade.php` PHP section**

Replace the entire PHP section with:

```php
<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\User;
use App\Notifications\AgentListingSubmitted;
use App\Services\InvitationService;
use App\Services\TwilioService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public bool $showModal = false;

    public bool $showSuccessModal = false;

    // Top fields (always required)
    public string $first_name = '';

    public string $last_name = '';

    public string $email = '';

    public string $phone = '';

    public string $company_name = '';

    // Listing fields (optional — triggers tier 2 when address + city filled)
    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public ?string $listing_price = null;

    public string $intent = 'selling';

    public ?int $createdAgentId = null;

    #[On('open-quick-add-agent')]
    public function openModal(): void
    {
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset([
            'first_name', 'last_name', 'email', 'phone', 'company_name',
            'address', 'city', 'state', 'zip', 'listing_price', 'intent',
            'createdAgentId',
        ]);
        $this->intent = 'selling';
        $this->resetValidation();
    }

    public function submitQuickAdd(): void
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:50'],
            'zip' => ['nullable', 'string', 'max:20'],
            'listing_price' => ['nullable', 'numeric', 'min:0'],
            'intent' => ['required', 'in:selling,buying'],
        ];

        $validated = $this->validate($rules);

        $isFullForm = ! empty($validated['address']) && ! empty($validated['city']);

        $user = DB::transaction(function () use ($validated, $isFullForm) {
            // Set type BEFORE save so boot hook creates AgentProfile
            $user = new User([
                'name' => trim($validated['first_name'].' '.$validated['last_name']),
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => bcrypt(str()->random(32)),
                'needs_onboarding' => ! $isFullForm,
            ]);
            $user->type = 'agent';
            $user->is_active = true;
            $user->save(); // created hook fires with type='agent' → AgentProfile auto-created

            // Update the auto-created AgentProfile with form data
            $user->agentProfile->update([
                'company_name' => $validated['company_name'] ?? null,
                'phone' => $validated['phone'] ?? null,
            ]);

            // Tier 2: Create property, deal, and estimate
            if ($isFullForm) {
                $property = Property::create([
                    'agent_id' => $user->id,
                    'source' => 'agent_referral',
                    'address' => $validated['address'],
                    'city' => $validated['city'],
                    'state' => strtoupper($validated['state'] ?? ''),
                    'zip' => $validated['zip'] ?? null,
                    'listing_status' => 'active',
                    'listing_price' => $validated['listing_price'] ?: null,
                ]);

                $deal = Deal::create([
                    'property_id' => $property->id,
                    'agent_id' => $user->id,
                    'title' => $validated['address'].', '.$validated['city'],
                    'stage' => 'lead',
                    'estimated_value' => $validated['listing_price'] ?: null,
                    'agent_intent' => $validated['intent'],
                    'listing_value' => $validated['listing_price'] ?: null,
                    'roi_scope' => 'interior',
                ]);

                Estimate::create([
                    'deal_id' => $deal->id,
                    'created_by' => auth()->id(),
                    'title' => 'Paint Estimate - '.$validated['address'],
                    'status' => 'draft',
                    'project_type' => 'interior',
                ]);

                // Notify internal team
                $internalUsers = User::where('type', 'internal')->get();
                foreach ($internalUsers as $internalUser) {
                    $internalUser->notify(new AgentListingSubmitted($deal, $user));
                }
            }

            return $user;
        });

        // Send invitation email
        $invitation = null;
        try {
            $invitationService = app(InvitationService::class);
            $invitation = $invitationService->sendInvitation(
                inviter: auth()->user(),
                email: $validated['email'],
                invitedUserType: 'agent',
                existingUserId: $user->id,
            );
        } catch (\InvalidArgumentException $e) {
            Log::warning('Quick-add agent: invitation failed', ['error' => $e->getMessage()]);
        }

        // Send SMS with invite link
        if ($invitation && ! empty($validated['phone'])) {
            try {
                $twilio = app(TwilioService::class);
                if ($twilio->isEnabled()) {
                    $acceptUrl = app(InvitationService::class)->generateAcceptUrl($invitation);
                    $companyName = CompanySetting::getInstance()->company_name ?? config('app.name');

                    $twilio->sendSms(
                        $validated['phone'],
                        __("Hi :name! You've been invited to join :company. Set up your account here: :link", [
                            'name' => $validated['first_name'],
                            'company' => $companyName,
                            'link' => $acceptUrl,
                        ]),
                    );
                }
            } catch (\Exception $e) {
                Log::warning('Quick-add agent: SMS failed', ['error' => $e->getMessage()]);
            }
        }

        $this->createdAgentId = $user->id;
        $this->showModal = false;
        $this->showSuccessModal = true;
    }

    public function goToAgent(): void
    {
        $this->redirect(route('agents.show', $this->createdAgentId));
    }

    public function stayOnHub(): void
    {
        $this->showSuccessModal = false;
        $this->dispatch('agent-quick-added');
        $this->reset([
            'first_name', 'last_name', 'email', 'phone', 'company_name',
            'address', 'city', 'state', 'zip', 'listing_price', 'intent',
            'createdAgentId',
        ]);
        $this->intent = 'selling';
    }
};
?>
```

- [ ] **Step 5: Update Blade template — add expandable listing section**

Replace the Blade template (everything after `?>`) with the updated version that includes the listing fields section between the Company field and the Actions. Add this block after the Company `</flux:field>` and before the Actions `<div>`:

```blade
{{-- Listing Details (optional — expands to tier 2) --}}
<div x-data="{ expanded: false }">
    <button type="button" @click="expanded = !expanded" class="flex items-center gap-2 text-sm text-blue-600 dark:text-blue-400 hover:underline">
        <flux:icon x-show="!expanded" name="plus" class="size-4" />
        <flux:icon x-show="expanded" name="minus" class="size-4" x-cloak />
        <span x-text="expanded ? '{{ __('Hide Listing Details') }}' : '{{ __('Add Listing Details (optional)') }}'"></span>
    </button>

    <div x-show="expanded" x-cloak class="mt-3 space-y-4 border-t border-zinc-200 dark:border-zinc-700 pt-4">
        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
            {{ __('Fill these out to skip agent onboarding and create a deal + estimate automatically.') }}
        </flux:text>

        <flux:field>
            <flux:input wire:model="address" label="{{ __('Property Address') }}" placeholder="123 Main St" />
            @error('address') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
        </flux:field>

        <div class="grid grid-cols-3 gap-3">
            <flux:field>
                <flux:input wire:model="city" label="{{ __('City') }}" placeholder="Denver" />
                @error('city') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
            </flux:field>
            <flux:field>
                <flux:input wire:model="state" label="{{ __('State') }}" placeholder="CO" />
                @error('state') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
            </flux:field>
            <flux:field>
                <flux:input wire:model="zip" label="{{ __('Zip') }}" placeholder="80202" />
                @error('zip') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
            </flux:field>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <flux:field>
                <flux:input wire:model="listing_price" type="number" label="{{ __('Listing Price') }}" placeholder="450000" />
                @error('listing_price') <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text> @enderror
            </flux:field>
            <flux:field>
                <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-2">{{ __('Intent') }}</flux:text>
                <div class="flex gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" wire:model="intent" value="selling" class="text-blue-600 focus:ring-blue-500" />
                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Selling') }}</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" wire:model="intent" value="buying" class="text-blue-600 focus:ring-blue-500" />
                        <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Buying') }}</span>
                    </label>
                </div>
            </flux:field>
        </div>
    </div>
</div>
```

- [ ] **Step 6: Run tests**

```bash
php artisan test --compact tests/Feature/Communications/QuickAddAgentTest.php
```

Expected: All PASS

- [ ] **Step 7: Run Pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 8: Commit**

```bash
git add resources/views/components/communications/⚡quick-add-agent.blade.php tests/Feature/Communications/QuickAddAgentTest.php
git commit -m "feat: agent quick-add with two-tier form, deal/estimate creation, and SMS"
```

---

### Task 5: Agent onboarding pre-population

**Files:**
- Modify: `resources/views/components/agent/⚡onboarding.blade.php:34-43`
- Test: `tests/Feature/Communications/QuickAddAgentTest.php` (add test)

- [ ] **Step 1: Write failing test**

Add to `tests/Feature/Communications/QuickAddAgentTest.php`:

```php
it('pre-populates agent onboarding with profile data from quick-add', function () {
    $user = User::factory()->create([
        'needs_onboarding' => true,
    ]);
    $user->type = 'agent';
    $user->save();
    $user->agentProfile->update([
        'company_name' => 'Pre-filled Brokerage',
        'phone' => '3035557777',
    ]);

    $this->actingAs($user, 'agent');

    Livewire::test('agent.onboarding')
        ->assertSet('companyName', 'Pre-filled Brokerage')
        ->assertSet('phone', '3035557777');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter="pre-populates agent onboarding"
```

Expected: FAIL — `companyName` is empty string, not 'Pre-filled Brokerage'

- [ ] **Step 3: Add pre-population to agent onboarding `mount()`**

In `resources/views/components/agent/⚡onboarding.blade.php`, add profile pre-population after the redirect check in `mount()` (after line 42):

```php
public function mount(): void
{
    $user = auth('agent')->user();

    if (! $user->needs_onboarding) {
        $this->redirect(route('agent.dashboard'));

        return;
    }

    // Pre-populate from AgentProfile if data was set by quick-add
    $profile = $user->agentProfile;
    if ($profile) {
        $this->companyName = $profile->company_name ?? '';
        $this->phone = $profile->phone ?? '';
        $this->licenseNumber = $profile->license_number ?? '';
    }
}
```

- [ ] **Step 4: Run test**

```bash
php artisan test --compact --filter="pre-populates agent onboarding"
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/agent/⚡onboarding.blade.php tests/Feature/Communications/QuickAddAgentTest.php
git commit -m "feat: agent onboarding pre-populates profile fields from quick-add data"
```

---

### Task 6: CustomerPreviewNotification + customer quick-add changes

**Files:**
- Create: `app/Notifications/CustomerPreviewNotification.php`
- Modify: `resources/views/components/communications/⚡quick-add-customer.blade.php`
- Test: `tests/Feature/Communications/QuickAddCustomerTest.php`

- [ ] **Step 1: Create notification**

```bash
php artisan make:notification CustomerPreviewNotification --no-interaction
```

- [ ] **Step 2: Write notification class**

Replace `app/Notifications/CustomerPreviewNotification.php` with:

```php
<?php

namespace App\Notifications;

use App\Models\CompanySetting;
use App\Models\ProjectPreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerPreviewNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ProjectPreview $preview,
        public string $customerName = '',
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $settings = CompanySetting::getInstance();
        $companyName = $settings->company_name ?? config('app.name');
        $subject = $settings->preview_link_email_subject ?: __('Your Project Preview from :company', ['company' => $companyName]);

        return (new MailMessage)
            ->subject($subject)
            ->greeting(__('Hi :name!', ['name' => $this->customerName ?: 'there']))
            ->line(__('Your project preview is ready. View your estimate details, photos, and project updates all in one place.'))
            ->action(__('View Your Project'), $this->preview->getPortalUrl())
            ->line(__('Thank you for choosing :company!', ['company' => $companyName]));
    }
}
```

- [ ] **Step 3: Create test file**

```bash
php artisan make:test Communications/QuickAddCustomerTest --pest --no-interaction
```

- [ ] **Step 4: Write tests**

Write to `tests/Feature/Communications/QuickAddCustomerTest.php`:

```php
<?php

use App\Models\Customer;
use App\Models\Invitation;
use App\Models\ProjectPreview;
use App\Models\User;
use App\Notifications\CustomerPreviewNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->internal()->create());
});

it('does not send invitation email for customers', function () {
    Livewire::test('communications.quick-add-customer')
        ->set('first_name', 'No')
        ->set('last_name', 'Invite')
        ->set('email', 'noinvite@customer.com')
        ->set('phone', '3035551234')
        ->set('estimate_preference', 'virtual')
        ->call('submitQuickAdd');

    expect(Invitation::where('email', 'noinvite@customer.com')->count())->toBe(0);
});

it('sends CustomerPreviewNotification email', function () {
    Notification::fake();

    Livewire::test('communications.quick-add-customer')
        ->set('first_name', 'Preview')
        ->set('last_name', 'Customer')
        ->set('email', 'preview@customer.com')
        ->set('phone', '3035555678')
        ->set('estimate_preference', 'virtual')
        ->call('submitQuickAdd');

    $customer = Customer::where('email', 'preview@customer.com')->first();

    Notification::assertSentTo(
        $customer,
        CustomerPreviewNotification::class,
    );
});

it('shows success modal after creation', function () {
    Livewire::test('communications.quick-add-customer')
        ->set('first_name', 'Success')
        ->set('last_name', 'Test')
        ->set('email', 'success@customer.com')
        ->set('estimate_preference', 'virtual')
        ->call('submitQuickAdd')
        ->assertSet('showSuccessModal', true);
});
```

- [ ] **Step 5: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Communications/QuickAddCustomerTest.php
```

- [ ] **Step 6: Update `⚡quick-add-customer.blade.php`**

In the PHP section, make these changes:

1. Add import at top: `use App\Notifications\CustomerPreviewNotification;`
2. Add import: `use App\Models\ProjectPreview;`
3. Remove import: `use App\Services\InvitationService;` (no longer needed)

4. Replace the invitation email block (lines 113-119) and add the preview notification:

Remove:
```php
// Send invitation email
try {
    $invitationService = app(InvitationService::class);
    $invitationService->sendInvitation(auth()->user(), $validated['email'], 'customer');
} catch (\InvalidArgumentException $e) {
    // User may already exist — that's okay, customer/deal still created
}
```

Replace with:
```php
// Send preview page email (not invite — customers access via preview page)
try {
    $preview = ProjectPreview::where('customer_id', $customer->id)
        ->where('deal_id', $deal->id)
        ->first();

    if (! $preview) {
        $preview = ProjectPreview::create([
            'customer_id' => $customer->id,
            'deal_id' => $deal->id,
            'estimate_id' => $deal->estimates()->first()?->id,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
        ]);
    }

    $customer->notify(new CustomerPreviewNotification(
        preview: $preview,
        customerName: $validated['first_name'],
    ));
} catch (\Exception $e) {
    \Illuminate\Support\Facades\Log::warning('Quick-add customer: preview notification failed', ['error' => $e->getMessage()]);
}
```

- [ ] **Step 7: Run tests**

```bash
php artisan test --compact tests/Feature/Communications/QuickAddCustomerTest.php
```

Expected: All PASS

- [ ] **Step 8: Run Pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 9: Commit**

```bash
git add app/Notifications/CustomerPreviewNotification.php resources/views/components/communications/⚡quick-add-customer.blade.php tests/Feature/Communications/QuickAddCustomerTest.php
git commit -m "feat: customer quick-add sends preview page email instead of invite"
```

---

### Task 7: Send Invitation page — customer type sends preview email

**Files:**
- Modify: `resources/views/components/invitations/⚡send-invitation.blade.php`
- Modify: `tests/Feature/InvitationSystemTest.php` (add test)

- [ ] **Step 1: Write failing test**

Add to `tests/Feature/InvitationSystemTest.php`:

```php
it('sends preview notification instead of invite when type is customer', function () {
    Notification::fake();

    $inviter = User::factory()->internal()->create();
    $customer = \App\Models\Customer::factory()->create(['email' => 'existing@customer.com']);
    $deal = \App\Models\Deal::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($inviter);

    Livewire::test('invitations.send-invitation')
        ->set('email', 'existing@customer.com')
        ->set('invitedUserType', 'customer')
        ->call('sendInvitation');

    Notification::assertSentTo(
        $customer,
        \App\Notifications\CustomerPreviewNotification::class,
    );

    expect(\App\Models\Invitation::where('email', 'existing@customer.com')->count())->toBe(0);
});
```

Add `use Illuminate\Support\Facades\Notification;` and `use Livewire\Livewire;` to imports if not present.

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter="sends preview notification instead of invite when type is customer"
```

- [ ] **Step 3: Update send-invitation component**

In `resources/views/components/invitations/⚡send-invitation.blade.php`, update the `sendInvitation()` method. Add imports at top:

```php
use App\Models\Customer;
use App\Models\ProjectPreview;
use App\Notifications\CustomerPreviewNotification;
use Illuminate\Support\Facades\Log;
```

Replace the `try` block in `sendInvitation()` with:

```php
try {
    if ($this->invitedUserType === 'customer') {
        // Customer: send preview page email instead of invite
        $customer = Customer::where('email', $this->email)->first();

        if (! $customer) {
            $this->addError('email', __('No customer found with this email address. Use Quick Add Customer to create one first.'));

            return;
        }

        $deal = $customer->deals()->latest()->first();
        $preview = null;

        if ($deal) {
            $preview = ProjectPreview::where('customer_id', $customer->id)
                ->where('deal_id', $deal->id)
                ->first();

            if (! $preview) {
                $preview = ProjectPreview::create([
                    'customer_id' => $customer->id,
                    'deal_id' => $deal->id,
                    'estimate_id' => $deal->estimates()->first()?->id,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'email' => $customer->email,
                    'phone' => $customer->phone,
                ]);
            }
        }

        if ($preview) {
            $customer->notify(new CustomerPreviewNotification(
                preview: $preview,
                customerName: $customer->first_name,
            ));
        } else {
            $this->addError('email', __('This customer has no deals yet. Create a deal first.'));

            return;
        }
    } else {
        // Agent/Crew/Internal: standard invite flow
        $invitationService->sendInvitation($user, $this->email, $this->invitedUserType, $this->serviceCategoryId);
    }

    $this->dispatch('invitation-sent');
    $flashMessage = $this->invitedUserType === 'customer'
        ? __('Preview link sent to :email', ['email' => $this->email])
        : __('Invitation sent to :email', ['email' => $this->email]);
    session()->flash('success', $flashMessage);
    $this->closeModal();
} catch (\InvalidArgumentException $e) {
    $this->addError('email', $e->getMessage());
}
```

- [ ] **Step 4: Run test**

```bash
php artisan test --compact --filter="sends preview notification instead of invite when type is customer"
```

Expected: PASS

- [ ] **Step 5: Run full invitation tests**

```bash
php artisan test --compact tests/Feature/InvitationSystemTest.php
```

Expected: All pass

- [ ] **Step 6: Run Pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/invitations/⚡send-invitation.blade.php tests/Feature/InvitationSystemTest.php
git commit -m "feat: send-invitation page sends preview email for customers"
```

---

### Task 8: Run full test suite and verify no regressions

**Files:**
- Read-only verification pass

- [ ] **Step 1: Run all communications hub tests**

```bash
php artisan test --compact tests/Feature/Communications/
```

Expected: All pass

- [ ] **Step 2: Run all invitation tests**

```bash
php artisan test --compact tests/Feature/InvitationSystemTest.php tests/Feature/Notifications/InvitationViewedTest.php
```

Expected: All pass

- [ ] **Step 3: Run hub tests**

```bash
php artisan test --compact tests/Feature/Communications/CommunicationsHubTest.php tests/Feature/Livewire/Customers/CommunicationsHubSmsTest.php
```

Expected: All pass

- [ ] **Step 4: Run Pint on all changed files**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 5: Commit only if fixups needed**

Only commit if Pint or fixes were required.

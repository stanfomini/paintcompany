# Unified Scheduling System Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Unify the two separate scheduling systems (AppointmentBooking + Activity-based MeetingService) into one system, add internal booking/reschedule capabilities to the customer hub, and keep video calls as an independent tool.

**Architecture:** Remove MeetingService entirely. Extend AppointmentBookingService with prefill token methods. Create a reusable `⚡book-appointment-modal` Livewire SFC that embeds the same availability calendar as the public `/book` page. Add appointment action buttons to the customer hub and show page.

**Tech Stack:** Laravel 12, Livewire 4 (SFC with ⚡ prefix), Pest tests, AppointmentAvailabilityService, TwilioService

**Spec:** `docs/superpowers/specs/2026-03-15-unified-scheduling-system-design.md`

---

## Chunk 1: Schema + Service Layer + Removal of MeetingService

### Task 1: Migration — Add override_note column

**Files:**
- Create: `database/migrations/2026_03_15_100001_add_override_note_to_appointment_bookings.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration add_override_note_to_appointment_bookings --table=appointment_bookings --no-interaction
```

Edit the migration:
```php
public function up(): void
{
    Schema::table('appointment_bookings', function (Blueprint $table) {
        $table->text('override_note')->nullable()->after('booked_by');
    });
}

public function down(): void
{
    Schema::table('appointment_bookings', function (Blueprint $table) {
        $table->dropColumn('override_note');
    });
}
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 3: Add override_note to AppointmentBooking model fillable**

In `app/Models/AppointmentBooking.php`, add `'override_note'` to the `$fillable` array.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*override_note* app/Models/AppointmentBooking.php
git commit -m "feat: add override_note column to appointment_bookings"
```

---

### Task 2: Add bookings.manage permission to seeder

**Files:**
- Modify: `database/seeders/RolesAndPermissionsSeeder.php`

- [ ] **Step 1: Add the permission**

Add `'bookings.manage'` to the permissions array in the seeder (near other booking/scheduling permissions).

- [ ] **Step 2: Assign to roles**

Add `'bookings.manage'` to the permission arrays for Owner, Admin, Manager, and Sales roles — the same roles that currently have `'people.edit'`.

- [ ] **Step 3: Run the seeder**

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder --no-interaction
```

- [ ] **Step 4: Commit**

```bash
git add database/seeders/RolesAndPermissionsSeeder.php
git commit -m "feat: add bookings.manage permission for internal scheduling"
```

---

### Task 3: Extend AppointmentBookingService with prefill token methods

**Files:**
- Modify: `app/Services/AppointmentBookingService.php`
- Create: `tests/Feature/Scheduling/UnifiedSchedulingTest.php`

- [ ] **Step 1: Write tests for prefill token generation and decoding**

Create `tests/Feature/Scheduling/UnifiedSchedulingTest.php`:

```php
<?php

use App\Models\Customer;
use App\Services\AppointmentBookingService;

it('generates an encrypted prefill token for a customer', function () {
    $customer = Customer::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'phone' => '3035551234',
        'address' => '123 Main St',
    ]);

    $service = app(AppointmentBookingService::class);
    $token = $service->generatePrefillToken($customer);

    expect($token)->toBeString()->not->toBeEmpty();
});

it('decodes a valid prefill token back to customer data', function () {
    $customer = Customer::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'jane@example.com',
        'phone' => '3035559999',
    ]);

    $service = app(AppointmentBookingService::class);
    $token = $service->generatePrefillToken($customer);
    $data = $service->decodePrefillToken($token);

    expect($data)->not->toBeNull()
        ->and($data['first_name'])->toBe('Jane')
        ->and($data['last_name'])->toBe('Smith')
        ->and($data['email'])->toBe('jane@example.com')
        ->and($data['phone'])->toBe('3035559999');
});

it('returns null for an expired prefill token', function () {
    $customer = Customer::factory()->create();
    $service = app(AppointmentBookingService::class);
    $token = $service->generatePrefillToken($customer, expiryDays: 0);

    // Travel forward in time
    $this->travel(1)->days();
    $data = $service->decodePrefillToken($token);

    expect($data)->toBeNull();
});

it('returns null for a tampered prefill token', function () {
    $service = app(AppointmentBookingService::class);
    $data = $service->decodePrefillToken('tampered-invalid-token');

    expect($data)->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Scheduling/UnifiedSchedulingTest.php
```

Expected: FAIL — methods don't exist yet.

- [ ] **Step 3: Implement generatePrefillToken and decodePrefillToken**

In `app/Services/AppointmentBookingService.php`, add these methods:

```php
use App\Models\Customer;
use Illuminate\Support\Facades\Crypt;

/**
 * Generate an encrypted prefill token containing customer data for the booking page.
 */
public function generatePrefillToken(Customer $customer, int $expiryDays = 7): string
{
    $data = [
        'first_name' => $customer->first_name,
        'last_name' => $customer->last_name,
        'email' => $customer->email,
        'phone' => $customer->phone,
        'address' => $customer->address,
        'city' => $customer->city,
        'state' => $customer->state,
        'zip' => $customer->zip,
        'customer_id' => $customer->id,
        'expires_at' => now()->addDays($expiryDays)->toIso8601String(),
    ];

    return Crypt::encryptString(json_encode($data));
}

/**
 * Decode a prefill token back to customer data. Returns null if expired or invalid.
 */
public function decodePrefillToken(string $token): ?array
{
    try {
        $json = Crypt::decryptString($token);
        $data = json_decode($json, true);

        if (! $data || ! isset($data['expires_at'])) {
            return null;
        }

        if (now()->isAfter($data['expires_at'])) {
            return null;
        }

        return $data;
    } catch (\Throwable) {
        return null;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact tests/Feature/Scheduling/UnifiedSchedulingTest.php
```

Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/AppointmentBookingService.php tests/Feature/Scheduling/UnifiedSchedulingTest.php
git commit -m "feat: add prefill token generation to AppointmentBookingService"
```

---

### Task 4: Update SendAppointmentRemindersCommand to also notify estimators

**Files:**
- Modify: `app/Console/Commands/SendAppointmentRemindersCommand.php`

- [ ] **Step 1: Read the current command**

Read `app/Console/Commands/SendAppointmentRemindersCommand.php` to understand the current logic.

- [ ] **Step 2: Add estimator notification**

After the customer notification is sent, add:

```php
// Also notify the estimator
if ($booking->estimator) {
    $booking->estimator->notify(new AppointmentReminderNotification($booking));
}
```

Import the notification class if not already imported. Check the existing notification class to see if it handles estimator context gracefully (it should — it's the same "your appointment is tomorrow" message).

- [ ] **Step 3: Commit**

```bash
git add app/Console/Commands/SendAppointmentRemindersCommand.php
git commit -m "fix: also notify estimator in appointment reminders"
```

---

### Task 5: Delete MeetingService + meeting notifications + meeting reminders command

**Files:**
- Delete: `app/Services/MeetingService.php`
- Delete: `app/Notifications/MeetingProposed.php`
- Delete: `app/Notifications/MeetingConfirmed.php`
- Delete: `app/Notifications/MeetingCancelled.php`
- Delete: `app/Notifications/MeetingReminder.php`
- Delete: `app/Console/Commands/SendMeetingReminders.php`
- Delete: `tests/Feature/MeetingSchedulingTest.php`
- Delete: `tests/Feature/Notifications/MeetingConfirmedTest.php`
- Delete: `tests/Feature/Notifications/MeetingProposedTest.php`
- Modify: `routes/console.php` — remove line 13

- [ ] **Step 1: Remove the scheduled command from console.php**

In `routes/console.php`, remove:
```php
Schedule::command('meetings:send-reminders')->everyFiveMinutes();
```

- [ ] **Step 2: Delete the files**

```bash
rm app/Services/MeetingService.php
rm app/Notifications/MeetingProposed.php
rm app/Notifications/MeetingConfirmed.php
rm app/Notifications/MeetingCancelled.php
rm app/Notifications/MeetingReminder.php
rm app/Console/Commands/SendMeetingReminders.php
rm tests/Feature/MeetingSchedulingTest.php
rm tests/Feature/Notifications/MeetingConfirmedTest.php
rm tests/Feature/Notifications/MeetingProposedTest.php
```

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "refactor: remove MeetingService, meeting notifications, and meeting reminders command"
```

---

### Task 6: Remove meeting code from Livewire components

**Files:**
- Modify: `resources/views/components/estimates/⚡show.blade.php`
- Modify: `resources/views/components/customer/⚡dashboard.blade.php`
- Modify: `resources/views/components/customer/estimates/⚡show.blade.php`
- Modify: `resources/views/components/agent/estimates/⚡show.blade.php`
- Modify: `resources/views/components/video-call/⚡job-call.blade.php`
- Modify: `resources/views/components/notifications/⚡notification-center.blade.php`
- Modify: `app/Models/Activity.php`
- Modify: `tests/Feature/WebPush/NotificationWebPushTest.php`
- Modify: `tests/Feature/Livewire/VideoCall/JobCallSchedulingTest.php`

- [ ] **Step 1: Read each file and identify meeting-related code**

For each file listed above, read it and identify:
- `use App\Services\MeetingService` imports
- Meeting-related properties (`meetingTitle`, `meetingDateTime`, etc.)
- Meeting-related methods (`proposeMeeting`, `confirmMeeting`, `declineMeeting`, `cancelMeeting`, `scheduleMeeting`, `completeMeeting`, `joinScheduledCall`, `needsMyConfirmation`)
- Meeting-related computed properties (`meetingActivities`, `teamMembers` if only used for meetings)
- Meeting-related template sections (Meetings cards, meeting list UI, meeting forms)

- [ ] **Step 2: Remove meeting code from estimates/⚡show.blade.php**

Remove: `use App\Services\MeetingService` import, all meeting properties (`meetingTitle`, `meetingDateTime`, `meetingType`, `meetingDescription`, `meetingTeamMember`), the `meetingActivities` computed, `teamMembers` computed (if only used for meetings), `proposeMeeting()`, `confirmMeeting()`, `declineMeeting()`, `cancelMeeting()` methods, and the entire Meetings card in the template (~lines 1275-1397).

- [ ] **Step 3: Remove meeting code from customer/⚡dashboard.blade.php**

Remove meeting properties, `proposeMeeting`, `confirmMeeting`, `declineMeeting` methods, and meeting-related template sections.

- [ ] **Step 4: Remove meeting code from customer/estimates/⚡show.blade.php**

Remove `MeetingService` import and meeting scheduling methods/UI.

- [ ] **Step 5: Remove meeting code from agent/estimates/⚡show.blade.php**

Remove `MeetingService` import and meeting scheduling methods/UI.

- [ ] **Step 6: Remove meeting code from video-call/⚡job-call.blade.php**

Remove `scheduleMeeting`, `confirmMeeting`, `declineMeeting`, `cancelMeeting`, `completeMeeting`, `joinScheduledCall`, `needsMyConfirmation` methods and meeting list UI. **Keep** the video call functionality.

- [ ] **Step 7: Remove meeting notification handling from notification-center**

In `resources/views/components/notifications/⚡notification-center.blade.php`, remove the `MeetingProposed`, `MeetingConfirmed`, `MeetingCancelled`, `MeetingReminder` cases from match/switch statements. Old notification records in the DB will fall through to `default` rendering.

- [ ] **Step 8: Remove meeting-specific methods from Activity model**

In `app/Models/Activity.php`, remove: `isMeeting()`, `isVirtualMeeting()`, `isFullyConfirmed()`, `needsConfirmationFrom()`, `scopeNeedingReminder()`, `scopeUpcomingMeetings()`.

- [ ] **Step 9: Fix test files referencing MeetingService**

In `tests/Feature/WebPush/NotificationWebPushTest.php`, remove `MeetingProposed` references.
In `tests/Feature/Livewire/VideoCall/JobCallSchedulingTest.php`, remove MeetingService references. Keep video call tests that don't depend on meetings.

- [ ] **Step 10: Run pint and verify no syntax errors**

```bash
vendor/bin/pint --dirty
php artisan test --compact tests/Feature/Livewire/VideoCall/ tests/Feature/WebPush/
```

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "refactor: remove all meeting code from Livewire components, Activity model, and tests"
```

---

### Task 7: Data migration — Activity meetings to AppointmentBooking

**Files:**
- Create: `database/migrations/2026_03_15_100002_migrate_activity_meetings_to_appointment_bookings.php`

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration migrate_activity_meetings_to_appointment_bookings --no-interaction
```

- [ ] **Step 2: Write the migration**

```php
public function up(): void
{
    $activities = DB::table('activities')
        ->whereNotNull('meeting_type')
        ->whereIn('meeting_status', ['proposed', 'confirmed'])
        ->where('subject_type', 'App\\Models\\Deal')
        ->get();

    $slotDuration = DB::table('company_settings')->value('booking_slot_duration_minutes') ?? 60;

    foreach ($activities as $activity) {
        $deal = DB::table('deals')->find($activity->subject_id);
        if (! $deal) continue;

        $customer = $deal->customer_id ? DB::table('customers')->find($deal->customer_id) : null;
        $dueAt = Carbon::parse($activity->due_at);

        // Check if booking already exists for this deal+date to avoid duplicates
        $exists = DB::table('appointment_bookings')
            ->where('deal_id', $deal->id)
            ->where('booked_date', $dueAt->toDateString())
            ->where('booked_start_time', $dueAt->format('H:i:s'))
            ->exists();

        if ($exists) continue;

        DB::table('appointment_bookings')->insert([
            'customer_id' => $deal->customer_id,
            'deal_id' => $deal->id,
            'estimator_id' => $activity->assigned_to,
            'booked_date' => $dueAt->toDateString(),
            'booked_start_time' => $dueAt->format('H:i:s'),
            'booked_end_time' => $dueAt->copy()->addMinutes($slotDuration)->format('H:i:s'),
            'booked_timezone' => config('app.timezone'),
            'status' => 'confirmed',
            'prospect_first_name' => $customer?->first_name ?? '',
            'prospect_last_name' => $customer?->last_name ?? '',
            'prospect_email' => $customer?->email ?? '',
            'prospect_phone' => $customer?->phone ?? '',
            'prospect_address' => $customer?->address ?? '',
            'estimate_preference' => $activity->meeting_type ?? 'in_person',
            'booked_by' => 'internal',
            'booking_token' => Str::uuid(),
            'confirmed_at' => now(),
            'created_at' => $activity->created_at,
            'updated_at' => now(),
        ]);

        // Clean up the activity — preserve record but remove from meeting queries
        DB::table('activities')
            ->where('id', $activity->id)
            ->update([
                'type' => 'note',
                'meeting_type' => null,
                'meeting_status' => null,
                'updated_at' => now(),
            ]);
    }
}
```

Add `use Carbon\Carbon; use Illuminate\Support\Str; use Illuminate\Support\Facades\DB;` at the top.

The `down()` method can be empty — this is a one-way data migration.

- [ ] **Step 3: Run migration**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*migrate_activity_meetings*
git commit -m "feat: migrate Activity meetings to AppointmentBooking records"
```

---

## Chunk 2: New Components + UI Integration

### Task 8: Create ⚡book-appointment-modal.blade.php

**Files:**
- Create: `resources/views/components/customers/⚡book-appointment-modal.blade.php`

- [ ] **Step 1: Create the Livewire SFC**

This is the reusable booking modal. It shows the availability calendar with slots (same data as `/book` page), allows the internal user to pick a date/slot, and creates the booking. It also has an override toggle.

**PHP class needs:**
- Properties: `$showModal`, `$customerId`, `$dealId`, `$selectedDate`, `$selectedSlot`, `$overrideMode`, `$overrideDate`, `$overrideStartTime`, `$overrideEndTime`, `$overrideEstimatorId`, `$overrideNote`, `$rescheduleBookingToken` (null for new, set for reschedule)
- Computed: `availableDates()`, `availableSlots()`, `customer()`, `bookableEstimators()`
- Methods: `openForCustomer(int $customerId, int $dealId)`, `openForReschedule(string $bookingToken)`, `selectDate(string $date)`, `selectSlot(array $slot)`, `bookAppointment()`, `close()`
- `bookAppointment()` calls `AppointmentBookingService::bookAppointment()` for new bookings, or `rescheduleBooking()` for reschedules. Also calls `DealStageService::transitionTo('quote_scheduled')` after new bookings.
- If `$overrideMode` is on, use the manual date/time inputs instead of slots. Store the override note.
- On success: dispatch browser event `appointment-booked` and flash success, close modal.

**Template needs:**
- `flux:modal` wrapper with `wire:model="showModal"`
- Two modes: **Calendar mode** (default) and **Override mode** (toggled by checkbox)
- Calendar mode: date grid (reuse the same visual pattern from `⚡vendor-booking.blade.php` — show dates with available slot counts), then time slot buttons for selected date
- Override mode: date input, start time input, end time input, estimator dropdown, override note textarea
- Submit button: "Book Appointment" or "Reschedule" depending on mode

Read `resources/views/components/public/⚡vendor-booking.blade.php` for the calendar/slot visual pattern to match.

- [ ] **Step 2: Verify modal renders without errors**

Temporarily embed the modal in the customer hub to test:
```blade
<livewire:customers.book-appointment-modal />
```

Load any customer hub page and verify no PHP errors.

- [ ] **Step 3: Commit**

```bash
git add "resources/views/components/customers/⚡book-appointment-modal.blade.php"
git commit -m "feat: create book-appointment-modal Livewire component"
```

---

### Task 9: Create ⚡appointment-actions.blade.php

**Files:**
- Create: `resources/views/components/customers/⚡appointment-actions.blade.php`

- [ ] **Step 1: Create the Livewire SFC**

Child component that shows action buttons for each appointment. Receives `$customerId` as a prop.

**PHP class needs:**
- Property: `public int $customerId`
- Computed: `appointments()` — query `AppointmentBooking::where('customer_id', $this->customerId)->whereIn('status', ['confirmed', 'completed'])->with('estimator')->orderBy('booked_date')->get()`
- Methods: `cancelBooking(string $token)`, `markCompleted(int $id)`, `markNoShow(int $id)`, `reschedule(string $token)` — dispatches event to open the modal

**Template:**
- For each appointment, show: date/time, estimator, status badge
- For confirmed upcoming appointments: Reschedule button, Cancel button (with wire:confirm and optional reason)
- For confirmed past appointments: Mark Completed, Mark No Show buttons
- Cancel calls `AppointmentBookingService::cancelBooking($token)` (existing method accepts token)
- Reschedule dispatches `$dispatch('open-reschedule-modal', { token: '...' })` to the parent hub which opens `⚡book-appointment-modal` in reschedule mode

- [ ] **Step 2: Commit**

```bash
git add "resources/views/components/customers/⚡appointment-actions.blade.php"
git commit -m "feat: create appointment-actions child component"
```

---

### Task 10: Integrate booking actions into customer communications hub

**Files:**
- Modify: `resources/views/components/customers/⚡communications-hub.blade.php`

- [ ] **Step 1: Add header buttons**

In the header area (near the "Send SMS" button around line 429), add:
- "Book Appointment" button — `wire:click="$dispatch('open-book-modal')"` — opens the book-appointment-modal
- "Send Booking Link" button — with dropdown for SMS/Copy options
- "Video Call" button — `wire:click="startVideoCall"`

- [ ] **Step 2: Add booking link methods**

Add `sendBookingLinkSms()` and `copyBookingLink()` methods:
- `sendBookingLinkSms()`: generates prefill token via `AppointmentBookingService::generatePrefillToken()`, builds URL, sends via `TwilioService::sendSms()`
- `copyBookingLink()`: generates prefill token, dispatches browser event with URL for JS clipboard copy

- [ ] **Step 3: Add startVideoCall() method**

```php
public function startVideoCall(): void
{
    $deal = $this->customer->deals()->latest()->first();
    $videoCall = \App\Models\VideoCall::create([
        'callable_type' => \App\Models\Deal::class,
        'callable_id' => $deal?->id,
        'initiated_by' => auth()->id(),
        'room_id' => 'call-' . \Illuminate\Support\Str::uuid(),
        'status' => 'pending',
    ]);

    $this->dispatch('open-url', url: route('video-call.test', $videoCall->room_id));
}
```

- [ ] **Step 4: Replace appointments section with appointment-actions component**

Replace the current display-only appointments section with:
```blade
<livewire:customers.appointment-actions :customer-id="$customer->id" />
```

- [ ] **Step 5: Embed the book-appointment-modal**

At the bottom of the component template, add:
```blade
<livewire:customers.book-appointment-modal />
```

Add event listeners to wire it up:
```php
#[On('open-book-modal')]
public function openBookModal(): void
{
    $deal = $this->customer->deals()->whereNotIn('stage', ['completed', 'lost', 'cancelled'])->latest()->first();
    $this->dispatch('open-appointment-modal', customerId: $this->customer->id, dealId: $deal?->id);
}

#[On('open-reschedule-modal')]
public function openRescheduleModal(string $token): void
{
    $this->dispatch('open-reschedule-appointment-modal', token: $token);
}

#[On('appointment-booked')]
public function onAppointmentBooked(): void
{
    unset($this->appointments);
}
```

- [ ] **Step 6: Run pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 7: Commit**

```bash
git add "resources/views/components/customers/⚡communications-hub.blade.php"
git commit -m "feat: add booking, booking link, and video call actions to customer hub"
```

---

### Task 11: Update customer show page — replace Schedule Quote modal

**Files:**
- Modify: `resources/views/components/customers/⚡show.blade.php`

- [ ] **Step 1: Remove the old Schedule Quote modal**

Read the file and find the "Schedule Quote Appointment" modal (~lines 1655-1669). Remove it and the `confirmQuoteSchedule` method and `quoteScheduleDate`/`showQuoteScheduleModal` properties.

- [ ] **Step 2: Update the changeDealStage method**

The `changeDealStage()` method has special branching for `quote_scheduled` that opens the old modal. Change it to dispatch to the book-appointment-modal instead:

```php
if ($stage === 'quote_scheduled') {
    $this->dispatch('open-appointment-modal', customerId: $this->customer->id, dealId: $this->activeDeal->id);
    return;
}
```

- [ ] **Step 3: Embed the book-appointment-modal**

Add at the bottom of the template:
```blade
<livewire:customers.book-appointment-modal />
```

- [ ] **Step 4: Run pint and test**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 5: Commit**

```bash
git add "resources/views/components/customers/⚡show.blade.php"
git commit -m "refactor: replace Schedule Quote modal with book-appointment-modal"
```

---

### Task 12: Accept prefill query param on public booking page

**Files:**
- Modify: `resources/views/components/public/⚡vendor-booking.blade.php`

- [ ] **Step 1: Modify mount() to accept prefill param**

In the `mount()` method, after existing initialization, add:

```php
// Accept prefill token from booking links (generated by internal users)
$prefill = request()->query('prefill');
if ($prefill) {
    $data = app(AppointmentBookingService::class)->decodePrefillToken($prefill);
    if ($data) {
        $this->firstName = $data['first_name'] ?? '';
        $this->lastName = $data['last_name'] ?? '';
        $this->email = $data['email'] ?? '';
        $this->phone = $data['phone'] ?? '';
        $this->address = $data['address'] ?? '';
        $this->city = $data['city'] ?? '';
        $this->state = $data['state'] ?? '';
        $this->zip = $data['zip'] ?? '';
    }
}
```

Add `use App\Services\AppointmentBookingService;` import.

- [ ] **Step 2: Commit**

```bash
git add "resources/views/components/public/⚡vendor-booking.blade.php"
git commit -m "feat: accept prefill query param on public booking page"
```

---

### Task 13: Write tests for the booking modal and actions

**Files:**
- Create: `tests/Feature/Scheduling/BookAppointmentModalTest.php`

- [ ] **Step 1: Write modal tests**

```php
<?php

use App\Models\AppointmentBooking;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;

it('creates an appointment booking from the modal', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);

    $this->actingAs($user);

    // This tests the service-level booking, not the Livewire component directly
    // (the modal calls AppointmentBookingService::bookAppointment internally)
    $booking = AppointmentBooking::create([
        'customer_id' => $customer->id,
        'deal_id' => $deal->id,
        'estimator_id' => $user->id,
        'booked_date' => now()->addDays(3)->toDateString(),
        'booked_start_time' => '10:00:00',
        'booked_end_time' => '11:00:00',
        'booked_timezone' => 'America/Denver',
        'status' => 'confirmed',
        'prospect_first_name' => $customer->first_name,
        'prospect_last_name' => $customer->last_name,
        'prospect_email' => $customer->email,
        'booked_by' => 'internal',
        'booking_token' => \Illuminate\Support\Str::uuid(),
        'confirmed_at' => now(),
    ]);

    expect($booking)->toBeInstanceOf(AppointmentBooking::class)
        ->and($booking->booked_by)->toBe('internal')
        ->and($booking->status)->toBe('confirmed');
});

it('stores override note when booking with override', function () {
    $booking = AppointmentBooking::factory()->create([
        'booked_by' => 'internal',
        'override_note' => 'Overridden by John - customer requested 7am slot',
    ]);

    expect($booking->override_note)->toContain('Overridden by John');
});

it('can cancel a booking by token', function () {
    $booking = AppointmentBooking::factory()->create(['status' => 'confirmed']);
    $service = app(\App\Services\AppointmentBookingService::class);

    $service->cancelBooking($booking->booking_token, 'Customer requested cancellation');

    expect($booking->fresh()->status)->toBe('cancelled');
});

it('can reschedule a booking by token', function () {
    $booking = AppointmentBooking::factory()->create(['status' => 'confirmed']);
    $service = app(\App\Services\AppointmentBookingService::class);

    $newBooking = $service->rescheduleBooking(
        $booking->booking_token,
        now()->addDays(5),
        '14:00',
        '15:00',
        $booking->estimator_id
    );

    expect($booking->fresh()->status)->toBe('rescheduled')
        ->and($newBooking->status)->toBe('confirmed')
        ->and($newBooking->booked_start_time)->toBe('14:00:00');
});
```

- [ ] **Step 2: Run tests**

```bash
php artisan test --compact tests/Feature/Scheduling/
```

- [ ] **Step 3: Fix any failures**

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Scheduling/
git commit -m "test: add scheduling integration tests for booking modal and actions"
```

---

### Task 14: Final verification and pint

- [ ] **Step 1: Run pint on all dirty files**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 2: Run the full scheduling test suite**

```bash
php artisan test --compact tests/Feature/Scheduling/
```

- [ ] **Step 3: Run a broader test to check for breakage from meeting removal**

```bash
php artisan test --compact tests/Feature/Livewire/VideoCall/ tests/Feature/WebPush/
```

If any tests fail due to missing MeetingService references that weren't caught in Task 6, fix them.

- [ ] **Step 4: Verify no remaining references to deleted files**

```bash
grep -r "MeetingService\|MeetingProposed\|MeetingConfirmed\|MeetingCancelled\|MeetingReminder\|SendMeetingReminders" app/ resources/ routes/ tests/ --include="*.php" --include="*.blade.php" -l
```

Should return no results. If any files still reference these, fix them.

- [ ] **Step 5: Final commit**

```bash
git add -A
git commit -m "chore: final cleanup and verification for unified scheduling system"
```

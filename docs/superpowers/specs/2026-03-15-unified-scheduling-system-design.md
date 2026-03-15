# Unified Scheduling System — Design Spec

**Date:** 2026-03-15
**Status:** Draft
**Problem:** Two separate scheduling systems exist — `AppointmentBooking` (public `/book` page) and `Activity`-based meetings (`MeetingService` on estimate page). They don't communicate. An estimator can have a meeting scheduled via the estimate page and still show as "available" on the public booking page. Internal users have no way to schedule or reschedule appointments from the customer hub.

**Solution:** Unify under `AppointmentBooking`. Remove `MeetingService` and Activity-based meetings. Add internal scheduling actions to the customer hub. Keep video calls as an independent communication tool.

---

## What Gets Removed

### MeetingService + Activity-based meetings

**Delete these files entirely:**
- `app/Services/MeetingService.php`
- `app/Notifications/MeetingProposed.php`
- `app/Notifications/MeetingConfirmed.php`
- `app/Notifications/MeetingCancelled.php`
- `app/Notifications/MeetingReminder.php`
- `app/Console/Commands/SendMeetingReminders.php`

**Remove meeting code from these Livewire components:**
- `resources/views/components/estimates/⚡show.blade.php` — Remove the entire "Meetings" section (~lines 1275-1397), including `proposeMeeting`, `confirmMeeting`, `declineMeeting`, `cancelMeeting` methods and all meeting-related properties (`meetingTitle`, `meetingDateTime`, `meetingType`, `meetingDescription`, `meetingTeamMember`, `meetingActivities` computed)
- `resources/views/components/customer/⚡dashboard.blade.php` — Remove `proposeMeeting`, `confirmMeeting`, `declineMeeting` methods and meeting properties (lines ~22-31, 253-277, 286-299)
- `resources/views/components/customer/estimates/⚡show.blade.php` — Remove meeting scheduling code (lines ~238, 260, 271)
- `resources/views/components/agent/estimates/⚡show.blade.php` — Remove meeting scheduling code (lines ~284, 306, 317)
- `resources/views/components/video-call/⚡job-call.blade.php` — Remove `scheduleMeeting`, `confirmMeeting`, `declineMeeting`, `cancelMeeting`, `completeMeeting`, `joinScheduledCall`, `needsMyConfirmation` methods and meeting list UI. Keep the video call functionality itself.

**Remove scheduled command:**
- `routes/console.php` — Remove `Schedule::command('meetings:send-reminders')->everyFiveMinutes()` (line ~13). The existing `booking:send-reminders` hourly command already handles `AppointmentBooking` reminders.

**Update notification center:**
- `resources/views/components/notifications/⚡notification-center.blade.php` — Remove hardcoded handling for `MeetingProposed`, `MeetingConfirmed`, `MeetingCancelled`, `MeetingReminder` notification types (lines ~186, 246-247, 290-292, 328). Old notification records in the DB will gracefully fall through to `default` rendering.

**Delete or rewrite test files:**
- `tests/Feature/MeetingSchedulingTest.php` — Delete (30+ tests for removed MeetingService)
- `tests/Feature/Notifications/MeetingConfirmedTest.php` — Delete
- `tests/Feature/Notifications/MeetingProposedTest.php` — Delete
- `tests/Feature/WebPush/NotificationWebPushTest.php` — Remove `MeetingProposed` references
- `tests/Feature/Livewire/VideoCall/JobCallSchedulingTest.php` — Rewrite to remove MeetingService references, keep video call tests

**Migrate data:** Any existing `Activity` records with `meeting_type IS NOT NULL` get migrated to `AppointmentBooking` records (one-time migration).

**Keep:** `Activity` model itself (used for tasks, notes, other non-meeting activity types). `VideoCall` model stays — decoupled from meetings, becomes standalone.

### Bidirectional confirmation
- The propose → team confirm → customer confirm → agent confirm flow is removed entirely
- All bookings are immediately `confirmed` when created
- Columns `team_confirmed_at`, `customer_confirmed_at`, `agent_confirmed_at` on Activity become unused (only relevant for meeting-type activities which are being removed)

### Existing "Schedule Quote" modal
- `resources/views/components/customers/⚡show.blade.php` — Remove the existing "Schedule Quote Appointment" modal (~lines 1655-1669) that uses `DealStageService::transitionTo()` with a simple date picker. Replaced by the new `⚡book-appointment-modal` which uses real availability.

---

## What Gets Added

### 1. Customer Hub — "Send Booking Link" Action

**Location:** `resources/views/components/customers/⚡communications-hub.blade.php`

**Behavior:**
- Button in the customer hub header area (next to "Send SMS")
- Generates a personalized booking URL pre-filled with the customer's name, phone, email, and address
- URL format: `/book?prefill={encrypted_token}` — uses a short-lived encrypted token containing customer data
- Internal user can choose delivery method:
  - **SMS** — sends the link via `TwilioService::sendSms()` with a friendly message
  - **Copy** — copies link to clipboard for pasting into email/chat

**Implementation:**
- New method on `AppointmentBookingService`: `generatePrefillToken(Customer $customer): string` — encrypts customer data into a URL-safe token (expires in 7 days)
- New method: `decodePrefillToken(string $token): ?array` — decrypts and returns customer data. Returns null if expired or invalid.
- Modify `⚡vendor-booking.blade.php` mount to accept `prefill` query param and auto-fill form fields

**Expired token behavior:** If a customer clicks an expired prefill link, the booking page loads normally with empty fields. No error shown — they can still fill in their info manually. The prefill is a convenience, not a gate.

**Why encrypted token instead of plain query params:** Customer PII (name, email, phone, address) should not be visible in the URL. An encrypted token keeps the URL clean and the data private.

### 2. Customer Hub — "Book Appointment" Action

**Location:** `resources/views/components/customers/⚡communications-hub.blade.php`

**Behavior:**
- Button in the customer hub header area
- Opens a modal with the same availability calendar/slots from the `/book` page
- Pre-filled with customer data (no form needed — we already know who they are)
- Internal user selects date → sees available slots → picks one → booking created immediately as `confirmed`
- **Override toggle:** "Override availability" checkbox lets internal users book outside normal availability (for when they've coordinated directly with the estimator)
- On override: shows a simple date picker + time inputs instead of the slot calendar

**Implementation:**
- New Livewire SFC: `resources/views/components/customers/⚡book-appointment-modal.blade.php`
- Uses `AppointmentAvailabilityService::getAvailableDates()` and `getAvailableSlotsForDate()` for the calendar
- Creates booking via `AppointmentBookingService::bookAppointment()` with `booked_by: 'internal'`
- After creation: sends `AppointmentBookedNotification` to customer, `NewAppointmentNotification` to estimator (same as public flow)
- After creation: transitions the deal to `quote_scheduled` stage via `DealStageService::transitionTo()` (replaces the old "Schedule Quote" modal's stage transition logic)
- Refreshes the appointments section in the hub

**Override audit trail:** When override is used, the booking is created with `booked_by: 'internal'` and a note is stored in the `AppointmentBooking` record (new nullable `override_note` column) indicating who overrode and optionally why. This prevents mystery double-bookings.

### 3. Customer Hub — Reschedule/Cancel Actions

**Location:** `resources/views/components/customers/⚡communications-hub.blade.php` — appointments section

**Current state:** Appointments are display-only. No actions.

**New actions on each confirmed appointment:**
- **Reschedule** — Opens the book-appointment-modal pre-loaded with the current booking. Selecting a new slot calls `AppointmentBookingService::rescheduleBooking()` which marks the old one as `rescheduled` and creates a new one.
- **Cancel** — Confirmation dialog with optional reason. Calls `AppointmentBookingService::cancelBooking()`.
- **Mark Completed** — For past appointments. Calls `markCompleted()`.
- **Mark No Show** — For past appointments. Calls `markNoShow()`.

**Note on service method signatures:** The existing `rescheduleBooking()` and `cancelBooking()` accept a `string $token` parameter. For internal use, callers pass `$booking->booking_token`. No method signature changes needed.

### 4. Video Call Links — Independent Tool

**Location:** Customer hub, customer portal (`/projects/{deal}`), agent portal

**Behavior:**
- "Start Video Call" button generates a WebRTC room link
- Available to internal users, customers (from their portal), and agents (from their portal)
- Not tied to the booking system — it's a communication tool like SMS or chat
- Uses existing `VideoCall` model with `room_id` = `call-{uuid}`

**Implementation:**
- New method on customer hub: `startVideoCall()` — creates `VideoCall` record, returns room URL
- Reuse existing `video-call.test` route for the call room
- Add the button to the customer hub header alongside SMS/Communications buttons

**VideoCall callable:** `VideoCall` polymorphic `callable` currently points at `Deal::class`. This stays — video calls remain linked to deals, not bookings. No morph map changes needed.

### 5. Customer Show Page — Appointment Actions

**Location:** `resources/views/components/customers/⚡show.blade.php` — sidebar

**Behavior:**
- If active deal has a confirmed upcoming appointment: show it with reschedule/cancel actions
- "Book Appointment" button if no upcoming appointment exists
- Same modal component (`⚡book-appointment-modal`) used in both the show page and the hub
- **Replaces** the existing "Schedule Quote Appointment" modal (which used a simple date picker with no availability checking)

---

## Data Migration

### Activity meetings → AppointmentBooking

One-time migration: `2026_03_15_migrate_activity_meetings_to_appointment_bookings.php`

```
For each Activity WHERE meeting_type IS NOT NULL AND meeting_status IN ('proposed', 'confirmed'):
  1. Find the Deal via subject_id (subject_type = Deal::class)
  2. Find the Customer via deal.customer
  3. Create AppointmentBooking:
     - customer_id = deal.customer_id
     - deal_id = deal.id
     - estimator_id = activity.assigned_to
     - booked_date = activity.due_at (date part)
     - booked_start_time = activity.due_at (time part)
     - booked_end_time = activity.due_at + 1 hour (default duration from CompanySetting)
     - status = 'confirmed' (all become confirmed regardless of previous meeting_status)
     - prospect_first_name, prospect_last_name, etc. from Customer record
     - estimate_preference = activity.meeting_type (in_person/virtual)
     - booked_by = 'internal'
  4. VideoCall records: leave callable pointing at Deal (already correct, no change needed)
  5. Clean up Activity: set type = 'note', meeting_type = null, meeting_status = null
     (preserves the record for audit trail but removes it from meeting queries)
```

### Schema additions

Migration: `2026_03_15_add_override_note_to_appointment_bookings.php`
- Add `override_note` text nullable column to `appointment_bookings` — stores who overrode availability and why

Verify `booked_by` column exists (it does per the original migration at line 41). No schema change needed for it.

---

## Files to Create

| File | Purpose |
|------|---------|
| `resources/views/components/customers/⚡book-appointment-modal.blade.php` | Reusable booking modal with calendar, slots, override toggle |
| `resources/views/components/customers/⚡appointment-actions.blade.php` | Child component for appointment reschedule/cancel/complete/no-show actions. Keeps the hub focused — booking logic extracted here. |
| `database/migrations/..._migrate_activity_meetings_to_bookings.php` | One-time data migration |
| `database/migrations/..._add_override_note_to_appointment_bookings.php` | Override audit trail column |
| `tests/Feature/Scheduling/BookAppointmentModalTest.php` | Modal functionality tests |
| `tests/Feature/Scheduling/UnifiedSchedulingTest.php` | Integration tests — booking link generation, internal booking, reschedule, cancel |

## Files to Modify

| File | Changes |
|------|---------|
| `resources/views/components/customers/⚡communications-hub.blade.php` | Add Send Booking Link, Book Appointment, Video Call buttons in header. Embed new `⚡appointment-actions` child component in the appointments section for reschedule/cancel/complete/no-show actions. Hub stays focused — booking logic lives in the child components. |
| `resources/views/components/customers/⚡show.blade.php` | Replace "Schedule Quote" modal with book-appointment-modal. Add appointment actions in sidebar. |
| `resources/views/components/estimates/⚡show.blade.php` | Remove entire Meetings section (~120 lines), remove meeting properties/methods |
| `resources/views/components/customer/⚡dashboard.blade.php` | Remove meeting scheduling methods and properties |
| `resources/views/components/customer/estimates/⚡show.blade.php` | Remove meeting scheduling code |
| `resources/views/components/agent/estimates/⚡show.blade.php` | Remove meeting scheduling code |
| `resources/views/components/video-call/⚡job-call.blade.php` | Remove meeting methods, keep video call functionality |
| `resources/views/components/notifications/⚡notification-center.blade.php` | Remove MeetingProposed/Confirmed/Cancelled/Reminder handling |
| `app/Services/AppointmentBookingService.php` | Add `generatePrefillToken()`, `decodePrefillToken()` methods |
| `resources/views/components/public/⚡vendor-booking.blade.php` | Accept `prefill` query param to auto-fill form |
| `app/Models/Activity.php` | Remove meeting-specific methods (`isMeeting`, `isVirtualMeeting`, `isFullyConfirmed`, `needsConfirmationFrom`) and scopes (`scopeNeedingReminder`, `scopeUpcomingMeetings`) — now dead code |
| `app/Console/Commands/SendAppointmentRemindersCommand.php` | Also notify the estimator (`$booking->estimator`) in addition to the customer. The old `meetings:send-reminders` notified all deal parties; the existing command only notifies the customer. |
| `database/seeders/RolesAndPermissionsSeeder.php` | Add `bookings.manage` permission. Assign to Owner, Admin, Manager, Sales roles (same roles that currently have `people.edit`). |
| `routes/console.php` | Remove `meetings:send-reminders` scheduled command |
| `tests/Feature/WebPush/NotificationWebPushTest.php` | Remove MeetingProposed references |
| `tests/Feature/Livewire/VideoCall/JobCallSchedulingTest.php` | Rewrite to remove MeetingService references |

## Files to Delete

| File | Reason |
|------|--------|
| `app/Services/MeetingService.php` | Replaced by AppointmentBookingService |
| `app/Notifications/MeetingProposed.php` | No more propose flow |
| `app/Notifications/MeetingConfirmed.php` | No more confirm flow |
| `app/Notifications/MeetingCancelled.php` | Replaced by AppointmentCancelledNotification |
| `app/Notifications/MeetingReminder.php` | Replaced by AppointmentReminderNotification |
| `app/Console/Commands/SendMeetingReminders.php` | Replaced by existing booking:send-reminders command |
| `tests/Feature/MeetingSchedulingTest.php` | Tests removed MeetingService |
| `tests/Feature/Notifications/MeetingConfirmedTest.php` | Tests removed notification |
| `tests/Feature/Notifications/MeetingProposedTest.php` | Tests removed notification |

---

## What Stays the Same

- **Public `/book` page** — unchanged, continues to work as-is
- **`AppointmentAvailabilityService`** — unchanged, already handles all availability logic
- **`AppointmentBookingService`** — extended with prefill token methods, existing methods untouched
- **Booking settings page** — unchanged, continues to configure slots/hours/estimators
- **`booking:send-reminders` cron** — unchanged, sends reminders for all `AppointmentBooking` records (replaces the meeting reminders command)
- **`booking:clean-holds` cron** — unchanged, cleans up expired SlotHold records
- **SlotHold system** — unchanged, prevents double-booking
- **`VideoCall` model** — stays, callable continues pointing at Deal, decoupled from meetings

---

## Availability Rules (Unchanged)

All scheduling — public, internal, or via booking link — respects the same rules:
1. Business hours (from CompanySetting)
2. Business days (from CompanySetting)
3. Employee recurring availability (EmployeeAvailability)
4. PTO requests (approved = full day blocked)
5. Life commitments (recurring personal blocks)
6. Existing AppointmentBookings (prevents double-booking)
7. Active SlotHolds (prevents race conditions)
8. Load balancing (picks estimator with fewest bookings in current week)

Internal users can bypass all of these with the **override toggle**. Override usage is logged with the internal user's identity in `override_note`.

---

## Privacy & Permissions

- Prefill tokens are encrypted (not plain query params) — customer PII stays private
- Expired prefill tokens degrade gracefully — booking page shows empty fields, no error
- Internal booking modal requires `auth` + `bookings.manage` permission (new permission, more specific than `people.edit`)
- Video call room IDs are UUIDs — not guessable
- Override audit trail stored in `override_note` column

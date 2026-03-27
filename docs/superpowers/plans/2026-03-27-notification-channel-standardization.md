# Notification Channel Standardization - Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade all 45 internal notifications to support all 4 channels (database, broadcast, email, WebPush, SMS) with user preference gating.

**Architecture:** Each notification gets the same `via()` pattern with conditional email/push/SMS, plus `toWebPush()` and `toSms()` methods. All gated by existing `User::wantsEmail()`, `wantsPush()`, `wantsSms()` preference system. No new infrastructure needed.

**Tech Stack:** Laravel Notifications, WebPush (Minishlink), Twilio SMS, Livewire 4 SFC

**Spec:** `docs/superpowers/specs/2026-03-26-notification-channel-standardization-design.md`

---

## Reference: Gold Standard `via()` Pattern

Every notification's `via()` should follow this exact pattern. Copy-paste and adapt:

```php
// Standard (no user type restriction):
public function via(object $notifiable): array
{
    $channels = ['database', 'broadcast'];

    if (method_exists($notifiable, 'wantsEmail')
        ? $notifiable->wantsEmail($this->pushCategory)
        : true) {
        $channels[] = 'mail';
    }

    if (method_exists($notifiable, 'wantsPush')
        && $notifiable->wantsPush($this->pushCategory)) {
        $channels[] = WebPushChannel::class;
    }

    if ($this->shouldSendSms($notifiable)) {
        $channels[] = SmsChannel::class;
    }

    return $channels;
}

// With RestrictsToUserTypes:
public function via(object $notifiable): array
{
    if (! $this->isAllowedRecipient($notifiable)) {
        return [];
    }

    // ... same as above
}

// With AnonymousNotifiable (vendor contracts):
public function via(object $notifiable): array
{
    if ($notifiable instanceof AnonymousNotifiable) {
        return ['mail'];
    }

    // ... same as above
}
```

## Reference: Required Imports

```php
use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Notifications\Concerns\HasSmsChannel;
```

---

### Task 1: Add SMS to 32 notifications (already have mail + webpush)

These notifications already have `toMail()`, `toWebPush()`, `pushCategory`, database, and broadcast. They only need: `HasSmsChannel` trait, `toSms()` method, and SMS added to `via()`.

**Files to modify:** All in `app/Notifications/`

For each file below, make these 3 changes:
1. Add `use App\Channels\SmsChannel;` and `use App\Channels\SmsMessage;` and `use App\Notifications\Concerns\HasSmsChannel;` imports
2. Add `HasSmsChannel` to the `use` traits line (e.g., `use HasSmsChannel, Queueable;`)
3. Add SMS check to `via()`: `if ($this->shouldSendSms($notifiable)) { $channels[] = SmsChannel::class; }`
4. Add `toSms()` method with contextual message

- [ ] **Step 1: AgentInvitationNotification.php** — pushCategory: `general`
```php
public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "New agent invitation sent to {$this->invitation->email}.",
        deepLink: route('invitations.index'),
    );
}
```

- [ ] **Step 2: AgentListingSubmitted.php** — pushCategory: `general`
```php
public function toSms(object $notifiable): SmsMessage
{
    $agentName = $this->agent->name ?? 'An agent';
    return new SmsMessage(
        body: "{$agentName} submitted a new listing for review.",
        deepLink: url("/deals/{$this->deal->id}"),
    );
}
```

- [ ] **Step 3: AgentPassedEstimate.php** — pushCategory: `estimates`
```php
public function toSms(object $notifiable): SmsMessage
{
    $agentName = $this->agent->name ?? 'An agent';
    return new SmsMessage(
        body: "{$agentName} passed on deal: {$this->deal->title}.",
        deepLink: url("/deals/{$this->deal->id}"),
    );
}
```

- [ ] **Step 4: CollaboratorAccessGranted.php** — pushCategory: `general`
```php
public function toSms(object $notifiable): SmsMessage
{
    $inviterName = $this->inviter->name ?? 'Someone';
    return new SmsMessage(
        body: "{$inviterName} granted you collaborator access to a deal.",
        deepLink: url("/deals/{$this->deal->id}"),
    );
}
```

- [ ] **Step 5: CrewCompliancePackNotification.php** — pushCategory: `general`
```php
public function toSms(object $notifiable): SmsMessage
{
    $name = $this->user->name ?? 'Team member';
    return new SmsMessage(
        body: "Compliance pack assigned to {$name}. Please review and complete required documents.",
        deepLink: url('/my/compliance'),
    );
}
```

- [ ] **Step 6: CrewOnboardingCallRequested.php** — pushCategory: `jobs`
```php
public function toSms(object $notifiable): SmsMessage
{
    $crewName = $this->crew->user?->name ?? 'A crew member';
    return new SmsMessage(
        body: "{$crewName} requested an onboarding call.",
        deepLink: url('/crew'),
    );
}
```

- [ ] **Step 7: CustomerPortalLogin.php** — pushCategory: `jobs`, RestrictsToUserTypes: internal
```php
public function toSms(object $notifiable): SmsMessage
{
    $customerName = $this->customer->full_name ?? 'A customer';
    return new SmsMessage(
        body: "{$customerName} just logged into the customer portal.",
        deepLink: route('customers.show', $this->customer),
    );
}
```

- [ ] **Step 8: DocumentExpiring.php** — pushCategory: `documents`
```php
public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "Document '{$this->document->name}' is expiring soon. Please renew.",
        deepLink: url('/documents'),
    );
}
```

- [ ] **Step 9: DocumentRejected.php** — pushCategory: `documents`
```php
public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "Document '{$this->document->name}' was rejected. Please resubmit.",
        deepLink: url('/documents'),
    );
}
```

- [ ] **Step 10: DocumentUploadedForReview.php** — pushCategory: `documents`
```php
public function toSms(object $notifiable): SmsMessage
{
    $uploaderName = $this->document->employeeProfile?->user?->name ?? 'Someone';
    return new SmsMessage(
        body: "{$uploaderName} uploaded '{$this->document->name}' for review.",
        deepLink: url('/documents'),
    );
}
```

- [ ] **Step 11: EmployeeWelcome.php** — pushCategory: `general`
```php
public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "Welcome to the team! Your account has been set up.",
        deepLink: url('/dashboard'),
    );
}
```

- [ ] **Step 12: EstimateAmended.php** — pushCategory: `estimates`
```php
public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "Estimate #{$this->estimate->estimate_number} has been amended.",
        deepLink: route('estimates.show', $this->estimate),
    );
}
```

- [ ] **Step 13: EstimatePackageUpdated.php** — pushCategory: `estimates`
```php
public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "Estimate #{$this->estimate->estimate_number} package was updated.",
        deepLink: route('estimates.show', $this->estimate),
    );
}
```

- [ ] **Step 14: EstimateReadyForAgent.php** — pushCategory: `estimates`, RestrictsToUserTypes: agent
```php
public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "Estimate #{$this->estimate->estimate_number} is ready for your review.",
        deepLink: route('estimates.show', $this->estimate),
    );
}
```

- [ ] **Step 15: InvitationNotification.php** — pushCategory: `general`
```php
public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "You've been invited to join. Check your email for details.",
        deepLink: url('/dashboard'),
    );
}
```

- [ ] **Step 16: MessengerHumanTakeoverNeeded.php** — pushCategory: `messenger`, RestrictsToUserTypes: internal
```php
public function toSms(object $notifiable): SmsMessage
{
    $customerName = $this->customer->full_name ?? 'A customer';
    return new SmsMessage(
        body: "{$customerName} needs human help in Messenger. Please take over.",
        deepLink: url("/deals/{$this->deal->id}"),
    );
}
```

- [ ] **Step 17: MissedCallNotification.php** — pushCategory: `phone_calls`
```php
public function toSms(object $notifiable): SmsMessage
{
    $callerName = $this->callLog->customer?->full_name ?? $this->callLog->from_number;
    return new SmsMessage(
        body: "Missed call from {$callerName}. Please call back.",
        deepLink: url('/communications'),
    );
}
```

- [ ] **Step 18: NewAccountCreated.php** — pushCategory: `general`, RestrictsToUserTypes: internal
```php
public function toSms(object $notifiable): SmsMessage
{
    $name = $this->newUser->name ?? 'Someone';
    return new SmsMessage(
        body: "New account created for {$name}.",
        deepLink: url('/dashboard'),
    );
}
```

- [ ] **Step 19: NewContactWelcome.php** — pushCategory: `general`
```php
public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "Welcome! Your contact profile has been created.",
        deepLink: url('/dashboard'),
    );
}
```

- [ ] **Step 20: NewMessengerLead.php** — pushCategory: `messenger`, RestrictsToUserTypes: internal
```php
public function toSms(object $notifiable): SmsMessage
{
    $title = $this->isIncomplete ? 'Incomplete Messenger lead' : 'New Messenger lead';
    return new SmsMessage(
        body: "{$title}: {$this->customer->full_name}",
        deepLink: url("/deals/{$this->deal->id}"),
    );
}
```

- [ ] **Step 21: NewOpenHouseSignup.php** — pushCategory: `general`
```php
public function toSms(object $notifiable): SmsMessage
{
    $name = $this->customer->full_name ?? 'Someone';
    return new SmsMessage(
        body: "New open house signup from {$name}.",
        deepLink: url("/deals/{$this->listing->id}"),
    );
}
```

- [ ] **Step 22: NewProjectRequested.php** — pushCategory: `general`
```php
public function toSms(object $notifiable): SmsMessage
{
    $customerName = $this->customer->full_name ?? 'A customer';
    return new SmsMessage(
        body: "{$customerName} requested a new project.",
        deepLink: route('customers.show', $this->customer),
    );
}
```

- [ ] **Step 23: NewSupportChatNotification.php** — pushCategory: `chat`, RestrictsToUserTypes: internal
```php
public function toSms(object $notifiable): SmsMessage
{
    $name = $this->session->guest_name ?? 'A visitor';
    return new SmsMessage(
        body: "New support chat from {$name}.",
        deepLink: url('/chat'),
    );
}
```

- [ ] **Step 24: PayStubAvailable.php** — pushCategory: `payments`
```php
public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "Your pay stub is now available for review.",
        deepLink: url('/my/pay-stubs'),
    );
}
```

- [ ] **Step 25: ProposalViewed.php** — pushCategory: `estimates`, RestrictsToUserTypes: internal
```php
public function toSms(object $notifiable): SmsMessage
{
    $customerName = $this->estimate->deal?->customer?->full_name ?? 'Customer';
    return new SmsMessage(
        body: "{$customerName} viewed estimate #{$this->estimate->estimate_number}.",
        deepLink: route('estimates.show', $this->estimate),
    );
}
```

- [ ] **Step 26: PtoRequested.php** — pushCategory: `general`
```php
public function toSms(object $notifiable): SmsMessage
{
    $name = $this->ptoRequest->employeeProfile?->user?->name ?? 'An employee';
    return new SmsMessage(
        body: "{$name} submitted a PTO request for your review.",
        deepLink: url('/settings/pto'),
    );
}
```

- [ ] **Step 27: PtoStatusChanged.php** — pushCategory: `general`
```php
public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "Your PTO request has been {$this->status}.",
        deepLink: url('/my/pto'),
    );
}
```

- [ ] **Step 28: TaskAssigned.php** — pushCategory: `tasks`
```php
public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "Task assigned: {$this->activity->title}",
        deepLink: url('/dashboard'),
    );
}
```

- [ ] **Step 29: VendorOnboardingCompleted.php** — pushCategory: `general`
```php
public function toSms(object $notifiable): SmsMessage
{
    $vendorName = $this->vendor->name ?? 'A vendor';
    return new SmsMessage(
        body: "{$vendorName} completed their onboarding.",
        deepLink: url('/vendors'),
    );
}
```

- [ ] **Step 30: VendorOnboardingInvite.php** — pushCategory: `general`
```php
public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "You've been invited to complete vendor onboarding.",
        deepLink: url('/vendor-onboarding'),
    );
}
```

- [ ] **Step 31: VideoCallInvitation.php** — pushCategory: `general`
```php
public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "{$this->initiatorName} invited you to a video call.",
        deepLink: url("/video-call/{$this->videoCall->room_id}"),
    );
}
```

- [ ] **Step 32: VoicemailReceivedNotification.php** — pushCategory: `phone_calls`
```php
public function toSms(object $notifiable): SmsMessage
{
    $callerName = $this->callLog->customer?->full_name ?? $this->callLog->from_number;
    return new SmsMessage(
        body: "New voicemail from {$callerName}.",
        deepLink: url('/communications'),
    );
}
```

- [ ] **Step 33: Run Pint and verify**
```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 34: Commit**
```bash
git add app/Notifications/
git commit -m "feat: add SMS channel to 32 notifications

All internal notifications with existing mail+webpush now also
support SMS delivery, gated by user preferences."
```

---

### Task 2: Add SMS + toMail to 4 notifications (have webpush but no mail/SMS)

These have `toWebPush()` and database/broadcast but are missing `toMail()` and SMS.

- [ ] **Step 1: EmailReceivedNotification.php**

Add imports, `HasSmsChannel` trait, `pushCategory` already exists. Update `via()` to gold standard. Add:

```php
public function toMail(object $notifiable): MailMessage
{
    $sender = $this->message->from_name ?? $this->message->from_address;

    return (new MailMessage)
        ->subject(__('New Email from :sender', ['sender' => $sender]))
        ->line(__('You received a new email.'))
        ->line('**'.__('From').':** '.$sender)
        ->line('**'.__('Subject').':** '.$this->message->subject)
        ->action(__('View Email'), url("/email?threadId={$this->message->email_thread_id}"));
}

public function toSms(object $notifiable): SmsMessage
{
    $sender = $this->message->from_name ?? $this->message->from_address;
    return new SmsMessage(
        body: "New email from {$sender}: {$this->message->subject}",
        deepLink: url("/email?threadId={$this->message->email_thread_id}"),
    );
}
```

Note: `EmailReceivedNotification` does NOT implement `ShouldQueue`. Add `use Illuminate\Bus\Queueable;` and `implements ShouldQueue` and `use HasSmsChannel, Queueable;` trait line.

- [ ] **Step 2: InvitationViewed.php**

Add imports, `HasSmsChannel` trait. Update `via()` to gold standard (add mail + SMS checks). Add:

```php
public function toMail(object $notifiable): MailMessage
{
    return (new MailMessage)
        ->subject(__('Invitation Viewed'))
        ->line(__(':email viewed your invitation.', ['email' => $this->invitation->email]))
        ->action(__('View Dashboard'), url('/dashboard'));
}

public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "{$this->invitation->email} viewed your invitation.",
        deepLink: url('/dashboard'),
    );
}
```

- [ ] **Step 3: NewAppointmentNotification.php**

Add imports, `HasSmsChannel` trait. Update `via()` to gold standard (add mail + SMS checks). Add:

```php
public function toMail(object $notifiable): MailMessage
{
    $name = $this->booking->getProspectFullName();
    $date = $this->booking->booked_date->format('l, M j, Y');
    $time = $this->booking->booked_start_time;
    $dealUrl = $this->booking->deal_id ? route('deals.show', $this->booking->deal_id) : url('/');

    return (new MailMessage)
        ->subject(__('New Appointment: :name', ['name' => $name]))
        ->line(__(':name booked an estimate appointment.', ['name' => $name]))
        ->line('**'.__('Date').':** '.$date)
        ->line('**'.__('Time').':** '.$time)
        ->action(__('View Deal'), $dealUrl);
}

public function toSms(object $notifiable): SmsMessage
{
    $name = $this->booking->getProspectFullName();
    $date = $this->booking->booked_date->format('M j');
    $time = $this->booking->booked_start_time;
    return new SmsMessage(
        body: "New appointment: {$name} on {$date} at {$time}.",
        deepLink: $this->booking->deal_id ? url("/deals/{$this->booking->deal_id}") : null,
    );
}
```

- [ ] **Step 4: TerritoryScheduleAssigned.php**

Add imports, `HasSmsChannel` trait. Update `via()` to gold standard (add mail + SMS checks). Add:

```php
public function toMail(object $notifiable): MailMessage
{
    $territory = $this->schedule->territory;
    $date = $this->schedule->date->format('l, M j, Y');

    return (new MailMessage)
        ->subject(__('Territory Schedule: :name', ['name' => $territory?->name ?? 'Assignment']))
        ->line(__("You've been assigned to :territory on :date.", ['territory' => $territory?->name, 'date' => $date]))
        ->line('**'.__('Start Time').':** '.$this->schedule->start_time)
        ->line('**'.__('Meet Address').':** '.($this->schedule->meet_address ?? 'TBD'))
        ->action(__('View Schedule'), $this->schedule->getMeetUpUrl() ?? url('/my/schedule'));
}

public function toSms(object $notifiable): SmsMessage
{
    $territory = $this->schedule->territory;
    $date = $this->schedule->date->format('M j');
    return new SmsMessage(
        body: "Assigned to {$territory?->name} on {$date} at {$this->schedule->start_time}.",
        deepLink: $this->schedule->getMeetUpUrl() ?? url('/my/schedule'),
    );
}
```

- [ ] **Step 5: Run Pint and commit**
```bash
vendor/bin/pint --dirty --format agent
git add app/Notifications/
git commit -m "feat: add mail + SMS to 4 notifications missing email"
```

---

### Task 3: Add WebPush to 2 appointment notifications (have mail + SMS but no push)

These have `HasSmsChannel`, `toMail()`, `toSms()` but are missing `toWebPush()` and WebPush in `via()`.

- [ ] **Step 1: AppointmentBookedNotification.php**

Add `use App\Channels\WebPushChannel;` and `use App\Channels\WebPushMessage;` imports. Add WebPush check to `via()`. Add:

```php
public function toWebPush(object $notifiable): WebPushMessage
{
    $date = $this->booking->booked_date->format('M j, Y');
    $time = $this->booking->booked_start_time;

    return new WebPushMessage(
        title: __('Appointment Confirmed'),
        body: "Estimate appointment on {$date} at {$time}",
        url: '/dashboard',
        tag: "booking-{$this->booking->id}",
    );
}
```

- [ ] **Step 2: AppointmentReminderNotification.php**

Add WebPush imports. Add WebPush check to `via()`. Add:

```php
public function toWebPush(object $notifiable): WebPushMessage
{
    $date = $this->booking->booked_date->format('M j');
    $time = $this->booking->booked_start_time;

    return new WebPushMessage(
        title: __('Appointment Reminder'),
        body: "Upcoming appointment on {$date} at {$time}",
        url: '/dashboard',
        tag: "booking-reminder-{$this->booking->id}",
    );
}
```

- [ ] **Step 3: Run Pint and commit**
```bash
vendor/bin/pint --dirty --format agent
git add app/Notifications/
git commit -m "feat: add WebPush to appointment notifications"
```

---

### Task 4: Full upgrade for AppointmentCancelledNotification (only has database + broadcast)

- [ ] **Step 1: Rewrite AppointmentCancelledNotification.php**

Replace entire file content with:

```php
<?php

namespace App\Notifications;

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\AppointmentBooking;
use App\Notifications\Concerns\HasSmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AppointmentCancelledNotification extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'appointments';

    public function __construct(public AppointmentBooking $booking) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = ['database', 'broadcast'];

        if (method_exists($notifiable, 'wantsEmail')
            ? $notifiable->wantsEmail($this->pushCategory)
            : true) {
            $channels[] = 'mail';
        }

        if (method_exists($notifiable, 'wantsPush')
            && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
        }

        if ($this->shouldSendSms($notifiable)) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->booking->getProspectFullName();
        $date = $this->booking->booked_date->format('l, M j, Y');

        return (new MailMessage)
            ->subject(__('Appointment Cancelled'))
            ->line(__(':name cancelled their appointment on :date.', ['name' => $name, 'date' => $date]))
            ->line('**'.__('Reason').':** '.($this->booking->cancellation_reason ?? __('No reason provided')))
            ->action(__('View Dashboard'), url('/dashboard'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'booking_id' => $this->booking->id,
            'prospect_name' => $this->booking->getProspectFullName(),
            'date' => $this->booking->booked_date->toDateString(),
            'time' => $this->booking->booked_start_time,
            'reason' => $this->booking->cancellation_reason,
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $name = $this->booking->getProspectFullName();

        return new WebPushMessage(
            title: __('Appointment Cancelled'),
            body: "{$name} cancelled their appointment",
            url: '/dashboard',
            tag: "booking-cancel-{$this->booking->id}",
        );
    }

    public function toSms(object $notifiable): SmsMessage
    {
        $name = $this->booking->getProspectFullName();
        return new SmsMessage(
            body: "{$name} cancelled their appointment.",
            deepLink: url('/dashboard'),
        );
    }
}
```

- [ ] **Step 2: Run Pint and commit**
```bash
vendor/bin/pint --dirty --format agent
git add app/Notifications/AppointmentCancelledNotification.php
git commit -m "feat: full channel upgrade for AppointmentCancelledNotification"
```

---

### Task 5: Upgrade 4 vendor contract notifications (have mail + database only)

These need broadcast, WebPush, SMS added. They also use `AnonymousNotifiable` — keep that check returning `['mail']` only for anonymous recipients.

- [ ] **Step 1: VendorContractExpired.php**

Add all imports + `HasSmsChannel` trait + `pushCategory = 'documents'`. Replace `via()`:

```php
public function via(object $notifiable): array
{
    if ($notifiable instanceof AnonymousNotifiable) {
        return ['mail'];
    }

    $channels = ['database', 'broadcast'];

    if (method_exists($notifiable, 'wantsEmail')
        ? $notifiable->wantsEmail($this->pushCategory)
        : true) {
        $channels[] = 'mail';
    }

    if (method_exists($notifiable, 'wantsPush')
        && $notifiable->wantsPush($this->pushCategory)) {
        $channels[] = WebPushChannel::class;
    }

    if ($this->shouldSendSms($notifiable)) {
        $channels[] = SmsChannel::class;
    }

    return $channels;
}
```

Add methods:
```php
public function toWebPush(object $notifiable): WebPushMessage
{
    return new WebPushMessage(
        title: __('Contract Expired'),
        body: "{$this->contract->title} - {$this->contract->vendor?->name}",
        url: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
        tag: "vendor-contract-{$this->contract->id}",
    );
}

public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "Contract expired: {$this->contract->title} ({$this->contract->vendor?->name}).",
        deepLink: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
    );
}
```

- [ ] **Step 2: VendorContractExpiringSoon.php** — same pattern, pushCategory: `documents`

```php
public function toWebPush(object $notifiable): WebPushMessage
{
    $expiresAt = $this->contract->expires_at?->format('M j, Y') ?? 'soon';
    return new WebPushMessage(
        title: __('Contract Expiring Soon'),
        body: "{$this->contract->title} expires {$expiresAt}",
        url: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
        tag: "vendor-contract-{$this->contract->id}",
    );
}

public function toSms(object $notifiable): SmsMessage
{
    $expiresAt = $this->contract->expires_at?->format('M j') ?? 'soon';
    return new SmsMessage(
        body: "Contract expiring {$expiresAt}: {$this->contract->title}.",
        deepLink: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
    );
}
```

- [ ] **Step 3: VendorContractFullyExecuted.php** — same pattern, pushCategory: `documents`

```php
public function toWebPush(object $notifiable): WebPushMessage
{
    return new WebPushMessage(
        title: __('Contract Fully Executed'),
        body: "{$this->contract->title} signed by all parties",
        url: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
        tag: "vendor-contract-{$this->contract->id}",
    );
}

public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "Contract fully signed: {$this->contract->title}.",
        deepLink: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
    );
}
```

- [ ] **Step 4: VendorContractInternalSigningRequest.php** — pushCategory: `documents`

This one does NOT have `AnonymousNotifiable` check (it's always internal). Add full `via()` pattern without the anonymous check.

```php
public function toWebPush(object $notifiable): WebPushMessage
{
    return new WebPushMessage(
        title: __('Contract Signing Required'),
        body: "{$this->contract->title} needs your signature",
        url: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
        tag: "vendor-contract-sign-{$this->contract->id}",
    );
}

public function toSms(object $notifiable): SmsMessage
{
    return new SmsMessage(
        body: "Contract needs your signature: {$this->contract->title}.",
        deepLink: route('vendor-contracts.sign-internal', ['vendor' => $this->contract->vendor_id, 'contract' => $this->contract->id]),
    );
}
```

- [ ] **Step 5: Run Pint and commit**
```bash
vendor/bin/pint --dirty --format agent
git add app/Notifications/
git commit -m "feat: add broadcast + WebPush + SMS to vendor contract notifications"
```

---

### Task 6: Verify all notifications compile and test

- [ ] **Step 1: Run Pint on all notification files**
```bash
vendor/bin/pint app/Notifications/ --format agent
```

- [ ] **Step 2: Run notification tests**
```bash
php artisan test --compact --filter=Notification
```

- [ ] **Step 3: Run appointment tests**
```bash
php artisan test --compact --filter=Appointment
```

- [ ] **Step 4: Run estimate tests**
```bash
php artisan test --compact --filter=Estimate
```

- [ ] **Step 5: Spot-check via tinker** — verify channels resolve correctly for a few upgraded notifications
```bash
php artisan tinker --execute '
$user = App\Models\User::where("type", "internal")->first();
$n = new App\Notifications\AppointmentCancelledNotification(App\Models\AppointmentBooking::first());
echo "Channels: " . implode(", ", $n->via($user)) . "\n";
'
```

- [ ] **Step 6: Final commit if any fixes needed**

---

### Task 7: Push to main

- [ ] **Step 1: Push**
```bash
git push origin main
```

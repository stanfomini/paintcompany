# Notification Channel Standardization

## Context

The app has 67 notification classes. The infrastructure for all 4 notification channels (email, WebPush, SMS, database/broadcast) is fully built and working. A per-user preference system (`wantsEmail()`, `wantsPush()`, `wantsSms()`) gates delivery per category. However, most notifications only implement a subset of channels — only 19 of 64 internal notifications have all 4 channels wired up. This means users can't choose how they get notified for many events.

**Goal:** Every internal-facing notification supports all channels: database (bell), broadcast (real-time), email, WebPush, and SMS — all controlled by user preferences.

## Scope

**In scope:** 64 internal-facing notifications — upgrade each to the gold standard pattern.

**Out of scope (3 external-only notifications):**
- `VendorContractSentToVendor` — email to external vendors
- `CustomerPreviewNotification` — email to customers
- `ProjectPreviewLinkNotification` — email/SMS to customers

## Gold Standard Pattern

Every notification follows this structure (exemplified by `EstimateAccepted`):

```php
use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Notifications\Concerns\HasSmsChannel;

class ExampleNotification extends Notification implements ShouldQueue
{
    use HasSmsChannel, Queueable;

    public string $pushCategory = 'category_name';

    public function via(object $notifiable): array
    {
        // Optional: RestrictsToUserTypes check
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

    public function toMail(object $notifiable): MailMessage { ... }
    public function toArray(object $notifiable): array { ... }  // database + broadcast
    public function toWebPush(object $notifiable): WebPushMessage { ... }
    public function toSms(object $notifiable): SmsMessage { ... }
}
```

## Push Category Mapping

Each notification maps to one of the 11 existing preference categories (from `User::defaultNotificationPreferences()`):

| Category | Notifications |
|----------|-------------|
| `estimates` | EstimateAccepted, EstimateRejected, EstimateProposalSent, EstimateAmended, EstimatePackageUpdated, EstimateReadyForAgent, ProposalViewed, ContractSentForSigning, AgentPassedEstimate |
| `appointments` | AppointmentBookedNotification, AppointmentCancelledNotification, AppointmentReminderNotification, NewAppointmentNotification |
| `jobs` | JobCreated, JobCompleted, JobStageAdvanced, CrewJobDispatched, CustomerConfirmedDate, CustomerProposedDate, CustomerConfirmedColors, SchedulePublished, TerritoryScheduleAssigned, CrewComplianceExpiring, CrewOnboardingCallRequested |
| `chat` | UnreadChatMessage, NewSupportChatNotification |
| `messenger` | NewMessengerLead, MessengerHumanTakeoverNeeded |
| `payments` | PaymentReceived, InvoiceSent, PayStubAvailable |
| `tasks` | TaskAssigned |
| `documents` | DocumentExpiring, DocumentRejected, DocumentUploadedForReview, CrewCompliancePackNotification, VendorContractExpired, VendorContractExpiringSoon, VendorContractFullyExecuted, VendorContractInternalSigningRequest |
| `phone_calls` | MissedCallNotification, VoicemailReceivedNotification |
| `email_messages` | EmailReceivedNotification |
| `general` | AgentInvitationNotification, AgentListingSubmitted, CollaboratorAccessGranted, CustomerPortalLogin, EmployeeWelcome, InvitationNotification, InvitationViewed, NewAccountCreated, NewContactWelcome, NewOpenHouseSignup, NewProjectRequested, PtoRequested, PtoStatusChanged, ReferralEstimateSigned, ReferralLeadCreated, ReferralRewardEarned, VendorOnboardingCompleted, VendorOnboardingInvite, VideoCallInvitation |

## Notifications by Gap Type

### Already complete (19) — no changes needed
ContractSentForSigning, CrewComplianceExpiring, CrewJobDispatched, CustomerConfirmedColors, CustomerConfirmedDate, CustomerProposedDate, EstimateAccepted, EstimateProposalSent, EstimateRejected, InvoiceSent, JobCompleted, JobCreated, JobStageAdvanced, PaymentReceived, ReferralEstimateSigned, ReferralLeadCreated, ReferralRewardEarned, SchedulePublished, UnreadChatMessage

### Need SMS only (32) — have mail + webpush + database + broadcast
Add `HasSmsChannel` trait, `toSms()` method, SMS in `via()`.

AgentInvitationNotification, AgentListingSubmitted, AgentPassedEstimate, CollaboratorAccessGranted, CrewCompliancePackNotification, CrewOnboardingCallRequested, CustomerPortalLogin, DocumentExpiring, DocumentRejected, DocumentUploadedForReview, EmployeeWelcome, EstimateAmended, EstimatePackageUpdated, EstimateReadyForAgent, InvitationNotification, MessengerHumanTakeoverNeeded, MissedCallNotification, NewAccountCreated, NewContactWelcome, NewMessengerLead, NewOpenHouseSignup, NewProjectRequested, NewSupportChatNotification, PayStubAvailable, ProposalViewed, PtoRequested, PtoStatusChanged, TaskAssigned, VendorOnboardingCompleted, VendorOnboardingInvite, VideoCallInvitation, VoicemailReceivedNotification

### Need SMS + toMail (4) — have webpush + database + broadcast
Add `HasSmsChannel` trait, `toSms()`, `toMail()`, mail+SMS in `via()`.

EmailReceivedNotification, InvitationViewed, NewAppointmentNotification, TerritoryScheduleAssigned

### Need SMS + toWebPush (2) — have mail + SMS + database + broadcast
Add `toWebPush()`, WebPush in `via()`.

AppointmentBookedNotification, AppointmentReminderNotification

### Need all channels (1) — have only database + broadcast
Add `HasSmsChannel` trait, `toSms()`, `toMail()`, `toWebPush()`, `pushCategory`, full `via()`.

AppointmentCancelledNotification

### Need SMS + WebPush + broadcast (4) — have mail + database only
Add `HasSmsChannel` trait, `toSms()`, `toWebPush()`, `pushCategory`, broadcast + WebPush + SMS in `via()`.

VendorContractExpired, VendorContractExpiringSoon, VendorContractFullyExecuted, VendorContractInternalSigningRequest

## Implementation Rules

1. **toWebPush()**: Short title (under 50 chars), 1-line body with key info, URL for deep link, tag for notification dedup (e.g., `estimate-{id}`). Must return `WebPushMessage` instance. The PWA service worker handles display.

2. **toSms()**: 1-2 sentences max. Include the key info (who/what). Append deep link URL via the `SmsMessage` constructor. Must return `SmsMessage` instance.

3. **toMail()**: Follow existing patterns — subject line, greeting, key details, action button URL. Must return `MailMessage` instance.

4. **via()**: Always include `database` + `broadcast`. Email, WebPush, SMS are all conditional on user preferences. If the notification uses `RestrictsToUserTypes`, the type check runs first and returns `[]` if the user type doesn't match.

5. **pushCategory**: Use existing categories from `User::defaultNotificationPreferences()`. Do not create new categories. Map to the closest matching category.

6. **Preserve existing behavior**: Don't change `toArray()`, `RestrictsToUserTypes`, or any existing channel logic. Only ADD missing channels.

## Verification

- Run full notification test suite: `php artisan test --compact --filter=Notification`
- Verify each upgraded notification compiles by running Pint
- Spot-check 3-4 upgraded notifications by dispatching them in tinker and confirming channels resolve correctly
- Verify WebPush payloads have required fields (title, body, url, tag)
- Verify SMS messages are concise (under 160 chars where possible)

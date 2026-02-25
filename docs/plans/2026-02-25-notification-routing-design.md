# Notification Routing System — Design Document

**Date:** 2026-02-25
**Approach:** Hybrid — assigned-user routing + role-based notification permissions

## Problem

Internal notifications are hardcoded to blast all internal users or User ID 1. The owner gets flooded, estimators miss notifications for their own deals, and project managers get irrelevant noise.

## Design

### Core Principle

Every internal notification has two recipient pools:

1. **Assigned user** — the person responsible for the entity (deal, customer, job). Always receives the notification. This is automatic, no configuration needed.
2. **Role subscribers** — any role that has the notification permission also receives it. Configurable via the existing role-detail settings page.

### How It Works

**New `NotificationRouter` service** resolves recipients for a given notification + entity:

```php
$recipients = NotificationRouter::resolve(
    notification: 'estimate_accepted',
    entity: $estimate,
);
// Returns Collection<User> — deduplicated
```

Resolution logic:
1. Find the assigned user: `$entity->deal->assignedTo ?? $entity->deal->customer->assignedTo`
2. Find all internal users whose role has permission `notify.estimate_accepted`
3. Merge, deduplicate, filter to `isInternal()` only
4. Return the collection

**New notification permissions** (prefix `notify.`) added to Spatie:

| Permission | Notification | Category |
|---|---|---|
| `notify.estimate_accepted` | EstimateAccepted | Estimates |
| `notify.estimate_rejected` | EstimateRejected | Estimates |
| `notify.proposal_viewed` | ProposalViewed | Estimates |
| `notify.job_created` | JobCreated | Jobs |
| `notify.job_stage_advanced` | JobStageAdvanced | Jobs |
| `notify.customer_confirmed_date` | CustomerConfirmedDate | Jobs |
| `notify.customer_confirmed_colors` | CustomerConfirmedColors | Jobs |
| `notify.customer_proposed_date` | CustomerProposedDate | Jobs |
| `notify.new_account_created` | NewAccountCreated | General |
| `notify.new_support_chat` | NewSupportChatNotification | Chat |
| `notify.new_messenger_lead` | NewMessengerLead | Chat |
| `notify.customer_portal_login` | CustomerPortalLogin | General |

**Owner role** gets all `notify.*` permissions by default.
**Estimator role** gets estimate-related ones.
**Other roles** get none by default — admin configures via role-detail page.

### Assigned User Resolution

Each notification declares how to find its assigned user:

```php
// On each notification class
public function resolveAssignedUser(): ?User
```

| Notification | Assigned user path |
|---|---|
| EstimateAccepted | `$estimate->deal->assignedTo ?? $estimate->deal->customer->assignedTo` |
| EstimateRejected | Same as above |
| ProposalViewed | Same as above (already works this way) |
| JobCreated | `$job->deal->assignedTo ?? $job->deal->customer->assignedTo` |
| JobStageAdvanced | Same as above (already works this way) |
| CustomerConfirmed* | Same as above (already works this way) |
| NewAccountCreated | None — role-only notification |
| NewSupportChatNotification | None — role-only (unassigned chats) |
| NewMessengerLead | `MessengerSetting::default_assigned_to` (already works) |
| CustomerPortalLogin | `$customer->assignedTo` (already works) |

### UI Integration

The existing role-detail settings page (`⚡role-detail.blade.php`) already has a `permissionGroups` array that groups permissions into sections with checkbox toggles. We add a new "Notifications" group:

```php
'Notifications' => [
    'notify.estimate_accepted',
    'notify.estimate_rejected',
    'notify.proposal_viewed',
    'notify.job_created',
    'notify.job_stage_advanced',
    'notify.customer_confirmed_date',
    'notify.customer_confirmed_colors',
    'notify.customer_proposed_date',
    'notify.new_account_created',
    'notify.new_support_chat',
    'notify.new_messenger_lead',
    'notify.customer_portal_login',
],
```

No new UI components needed. The role-detail page already renders any permissions in the group as checkboxes.

### Dispatch Point Changes

Replace hardcoded recipient logic at each dispatch site:

**Before (EstimateSignature::booted):**
```php
$internalUsers = User::where('type', 'internal')->get();
foreach ($internalUsers as $user) {
    $user->notify(new EstimateAccepted($estimate));
}
```

**After:**
```php
$recipients = app(NotificationRouter::class)->resolve('notify.estimate_accepted', $estimate);
foreach ($recipients as $user) {
    $user->notify(new EstimateAccepted($estimate));
}
```

### Files

| Action | File |
|--------|------|
| Create | `app/Services/NotificationRouter.php` |
| Create | `database/migrations/..._seed_notification_permissions.php` |
| Create | `tests/Feature/Notifications/NotificationRoutingTest.php` |
| Modify | `app/Models/EstimateSignature.php` — EstimateAccepted dispatch |
| Modify | `resources/views/components/estimates/⚡show.blade.php` — EstimateRejected dispatch |
| Modify | `app/Services/JobService.php` — JobCreated dispatch |
| Modify | `app/Observers/UserObserver.php` — NewAccountCreated dispatch |
| Modify | `resources/views/pages/settings/⚡role-detail.blade.php` — add Notifications group |

### What Stays the Same

Notifications that are already correctly routed stay untouched:
- ProposalViewed — already uses assigned user
- JobStageAdvanced, CustomerConfirmedDate/Colors/ProposedDate — already use assigned user
- NewMessengerLead, MessengerHumanTakeoverNeeded — already use messenger settings
- CustomerPortalLogin — already uses customer's assigned user
- All customer/agent/crew-facing notifications — unchanged

### Per-User Preference Layer

The existing `notification_preferences` on the User model (email/push toggles per category) continues to work as the final layer. The routing determines *who* gets it; the preference determines *how* (email, push, both, neither).

```
Event → NotificationRouter (who?) → User preferences (email/push?) → Delivery
```

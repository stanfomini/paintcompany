# Customer Duplicate Detection & Merge

## Date: 2026-03-08

## Problem

Customers enter the CRM through 8 different paths (Messenger bot, referral forms, door knocks, webhooks, appointment bookings, manual UI, invitations, automation). Duplicate detection is inconsistent — some paths check email, one checks phone (by loading ALL customers into memory), most don't check names. There is no way to merge duplicate customer records. No alerts when duplicates are created.

## Design Decisions

- **Manual merge only** — duplicates are flagged, never auto-merged
- **Matching criteria (moderate)**: exact email OR normalized phone OR (first_name + last_name), all case-insensitive
- **Check at creation time only** — no retroactive scanning (can add later)
- **Scope**: Customer records primarily. Agent/crew staff matched as a lightweight alert (no merge for those).
- **Side-by-side picker** for field conflicts during merge

## Data Model

### New table: `customer_duplicate_flags`

| Column | Type | Purpose |
|--------|------|---------|
| id | PK | |
| customer_id | FK customers | The newly created customer |
| duplicate_customer_id | FK customers, nullable | The existing customer it matches |
| duplicate_user_id | FK users, nullable | If matched an agent/crew user instead |
| match_type | enum: email, phone, name | What triggered the match |
| status | enum: pending, merged, dismissed | Has it been dealt with? |
| resolved_by | FK users, nullable | Who merged or dismissed |
| resolved_at | timestamp, nullable | When |
| timestamps | | |

One flag per match pair per match type.

## Services

### CustomerDuplicateService

- `findPotentialDuplicates(array $customerData): Collection` — DB queries for email, phone, name matches
- `flagDuplicates(Customer $new, Collection $matches): void` — creates flag records
- `dismiss(CustomerDuplicateFlag $flag, User $resolvedBy): void`
- Also checks `users` table for agent/crew matches → sets `duplicate_user_id`

### CustomerMergeService

- `merge(Customer $primary, Customer $absorbed, array $fieldSelections): Customer`
- Reassigns: deals, properties, conversations, chat threads, activities, collaborations, appointments
- Applies field selections from side-by-side picker
- Soft-deletes absorbed customer
- Marks all related flags as `merged`
- Logs activity: "Customer X merged into Customer Y"
- Handles edge case: absorbed customer has portal User account (deactivate if primary also has one)

## UI

### Communications Hub — Red dot
- Customers with `pending` duplicate flags show red dot next to name
- Clicks through to customer profile

### Customer Profile — Banner
- Alert banner: "Possible duplicate: matches [Name - email]. [Review] [Dismiss]"
- Staff match variant: "Possible staff match: existing agent [Name]. [Dismiss]"
- "Review" → merge page, "Dismiss" → marks flag dismissed

### Merge Page
- Side-by-side comparison with radio buttons per field
- Pre-selects non-empty value (or primary's if both filled)
- Shows summary: "X deals, Y conversations, Z properties will be moved"
- Confirmation before merge

### Notification Enhancement
- NewMessengerLead and similar notifications append "Possible duplicate of [Name]" when flags exist

## Integration Points (8 creation paths)

All call `CustomerDuplicateService::flagDuplicates()` after customer creation:

1. UI Create Modal (`⚡create-modal.blade.php`)
2. Messenger Bot (`MessengerLeadCreationService`)
3. Appointment Booking (`AppointmentBookingService`)
4. Referral Form (`ReferralService`)
5. Door Knock (`DoorKnockService`)
6. Invitation Accept (`InvitationService`)
7. Webhook (`WebhookLeadProcessingService`)
8. Automation (`CreateCustomer` action)

Also fix: `MessengerLeadCreationService::findExistingCustomer()` phone matching — replace `Customer::get()` with DB query.

## Testing

- Duplicate detection: email, phone (normalized), name (case-insensitive), staff match
- No false positives, respects dismissed flags
- Flag creation from all 8 entry points
- Merge: reassigns all related records, soft-deletes absorbed, resolves flags
- Field conflict picker works correctly
- Red dot + banner UI
- Edge cases: multiple matches, cascading flags, dual portal accounts

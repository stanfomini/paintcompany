# Quick-Add Flows Redesign — Design Spec

**Date**: 2026-03-26
**Status**: Approved
**Scope**: Enhancement to communications hub quick-add forms + customer invitation flow change

## Problem

The communications hub has quick-add forms for creating customers, agents, and crew. The customer form is solid, but the agent and crew forms are bare-bones — they only create a user + empty profile and send an invite email. They don't send SMS, don't pre-populate onboarding data, and the agent form can't create deals/estimates. Additionally, customers currently receive invite emails that force account creation before they can view their estimate — they should instead receive a preview page link.

### Pre-Existing Bug: Agent/Crew Quick-Add Invitations Silently Fail

The current agent and crew quick-add forms create a `User` record first, then call `InvitationService::sendInvitation()`. But `sendInvitation()` checks for existing users by email (line 88-98 of `InvitationService.php`) and **throws an exception** if one exists. The quick-add catches this silently — meaning **no invitation is ever actually sent** for agents or crew created via quick-add today. This spec fixes this as part of the redesign.

### Pre-Existing Bug: User Fields Not Mass-Assignable

The current agent/crew quick-add forms pass `first_name`, `last_name`, and `type` to `User::create()`, but `User::$fillable` only has `name` (not first/last), and `type` is intentionally excluded to prevent privilege escalation. The `type` must be set explicitly after creation (as `acceptInvitation()` does at line 195-196).

## Goals

1. Crew quick-add sends SMS with invite link (in addition to email)
2. Agent quick-add has two tiers: basic (profile only) and full (profile + listing → deal + estimate)
3. Full agent form skips onboarding entirely (`needs_onboarding = false`)
4. Customer flow stops sending invite emails — sends preview page link via email and SMS instead
5. This change applies globally: both quick-add forms AND the Send Invitation page
6. Fix the invitation flow so agent/crew quick-adds actually send invitations

## Design

### User Type Flow Summary

| | Customer | Agent (top only) | Agent (full form) | Crew |
|---|---|---|---|---|
| Creates | Customer + Deal + Estimate | User + AgentProfile | User + AgentProfile + Property + Deal + Estimate | User + CrewProfile |
| Deal stage | lead | — | lead | — |
| needs_onboarding | N/A | true | false | true |
| SMS | Preview page link | Invite link | Invite link | Invite link |
| Email | Preview page link (NEW) | Invite email | Invite email | Invite email |
| On accept | N/A (no account invite) | Onboarding (pre-filled) | Straight to dashboard | Onboarding (pre-filled) |

### InvitationService Changes (Critical Foundation)

The current flow assumes the User does not exist when `sendInvitation()` and `acceptInvitation()` are called. For agent/crew quick-adds, the User already exists. Both methods need modification.

**`sendInvitation()` — new `$existingUserId` parameter**:
- Add optional parameter: `sendInvitation(User $inviter, string $email, string $invitedUserType, ..., ?int $existingUserId = null)`
- When `$existingUserId` is provided, skip the existing-user check (lines 87-98) and create the Invitation normally
- The invitation still stores the email, type, and inviter — but the accept flow will update the existing user instead of creating a new one
- Store `$existingUserId` on the Invitation model (new nullable `existing_user_id` column) so `acceptInvitation()` knows to update rather than create

**`acceptInvitation()` — handle pre-existing users**:
- Check if `$invitation->existing_user_id` is set
- If yes: find the existing User, update `name` and `password` from `$userData`, do NOT override `needs_onboarding` (preserve the value set by quick-add), skip profile creation (already done)
- If no: existing behavior (create new User, set type, create profile, etc.)
- This preserves backward compatibility for all existing invitation flows

**Migration**: Add `existing_user_id` nullable foreign key to `invitations` table.

### User Creation in Quick-Add Forms (Agent + Crew)

Both agent and crew quick-add forms must be fixed to properly create users:

```php
$user = User::create([
    'name' => trim($validated['first_name'] . ' ' . $validated['last_name']),
    'email' => $validated['email'],
    'phone' => $validated['phone'] ?? null,
    'password' => bcrypt(str()->random(32)),
    'needs_onboarding' => true, // or false for agent full-form
]);
$user->type = 'agent'; // or 'crew' — set explicitly, not via mass assignment
$user->save();
```

The `User::booted()` `created` hook will auto-create an empty `AgentProfile`/`CrewProfile`. The quick-add then **updates** that profile with form data (not creates a second one):

```php
$user->agentProfile->update([
    'company_name' => $validated['company_name'] ?? null,
    'phone' => $validated['phone'] ?? null,
]);
```

### Crew Quick-Add (`⚡quick-add-crew.blade.php`)

**New behavior**:
- Create User (type: crew, `needs_onboarding = true`) with correct field mapping
- Boot hook auto-creates empty CrewProfile → update it with company_name, phone
- Call `InvitationService::sendInvitation()` with `existingUserId: $user->id` to bypass the existing-user check
- Send SMS with invite link using `InvitationService::generateAcceptUrl($invitation)` via TwilioService
- Only send SMS if phone is provided

**Crew experience**: Gets text + email with invite link → clicks it → sets password → lands on onboarding → completes contracts, documents, etc.

### Agent Quick-Add (`⚡quick-add-agent.blade.php`)

**Two tiers**:

**Tier 1: Top only** (name, email, phone, company):
- Create User (type: agent, `needs_onboarding = true`)
- Update AgentProfile with company_name, phone
- Send invitation (with `existingUserId`) + SMS with invite link
- Agent clicks link → sets password → goes through onboarding (profile pre-filled)

**Tier 2: Full form** (top + listing details — when address AND city are filled):
- Create User (type: agent, `needs_onboarding = false`)
- Update AgentProfile with company_name, phone
- Create Property (address, city, state, zip)
- Create Deal (title: "Painting Estimate - {name}", stage: lead, lead_source: communications_hub, linked to property and agent's customer record)
- Create draft Estimate (linked to deal)
- Notify internal users via `AgentListingSubmitted` notification
- Send invitation (with `existingUserId`) + SMS with invite link
- Agent clicks link → sets password → straight to dashboard

**New form fields** (expandable/collapsible section):
- Address (string)
- City (string)
- State (string)
- Zip (string)
- Listing Price (nullable, numeric)
- Intent: Selling or Buying (radio buttons)

### Customer Quick-Add (`⚡quick-add-customer.blade.php`)

**Changes**:
- **Remove** the `InvitationService::sendInvitation()` call
- **Add** `CustomerPreviewNotification` email with preview page link
- Welcome SMS with preview link is already dispatched by `Deal::created` hook (line 41-49 of `Deal.php`) and `Customer::created` hook (line 59-64 of `Customer.php`) via `SendWelcomeSmsJob` — the Deal hook includes `dealId` so the SMS will contain the portal link. No SMS changes needed.
- Must ensure a `ProjectPreview` exists for the customer/deal so the portal URL resolves. Check if `LeadDeduplicationService::resolveOrCreate()` creates one, or add creation after deal/estimate creation.

### Send Invitation Page (`⚡send-invitation.blade.php`)

**Changes**:
- When `invitedUserType === 'customer'`: look up Customer by email, find their active Deal, find or create `ProjectPreview`, send `CustomerPreviewNotification` with preview page link
- Edge cases: if no Customer found by email, flash error "No customer found with this email." If customer has multiple deals, use the most recent one. If no deals, send a simpler welcome email without portal link.
- When `invitedUserType === 'agent'`, `'crew'`, or `'internal'`: existing invite email behavior unchanged

### New Notification: `CustomerPreviewNotification`

**Purpose**: Email that sends a customer their preview page URL instead of an account creation invite link.

**Email content**:
- Subject: "Your Project Preview from {company_name}"
- Body: Greeting with customer name, brief message about their project preview being ready, CTA button linking to `ProjectPreview::getPortalUrl()`
- Uses existing `CompanySetting` preview link settings if available (`preview_link_email_subject`, `preview_link_email_body`)

**Triggered from**:
- Customer quick-add form (after creating customer + deal + estimate)
- Send Invitation page (when type is customer)

### SMS Details

**Agent/Crew SMS** (invite link):
- Content: "Hi {name}! You've been invited to join {company_name}. Set up your account here: {invite_link}"
- Sent after `InvitationService::sendInvitation()` returns the Invitation model
- Use `app(InvitationService::class)->generateAcceptUrl($invitation)` for the signed URL
- Only sent if phone number is provided

**Customer SMS** (preview link):
- Already handled by `SendWelcomeSmsJob` dispatched from `Deal::created` hook
- Content uses `CompanySetting->welcome_sms_with_deal` template which includes `{portal_link}`
- `SendWelcomeSmsJob` has idempotency guard (`welcome_sms_sent_at`) to prevent double-sends

### Onboarding Pre-Population

**Agent onboarding** (`⚡onboarding.blade.php`):
- Step 1 (Complete Profile) has fields: `$companyName`, `$phone`, `$licenseNumber`
- Currently these properties initialize as empty strings — they do NOT read from `AgentProfile` in `mount()`
- **Change needed**: Add pre-population in `mount()` to read from the agent's `AgentProfile` if data exists:
  ```php
  $profile = auth()->user()->agentProfile;
  if ($profile) {
      $this->companyName = $profile->company_name ?? '';
      $this->phone = $profile->phone ?? '';
  }
  ```

**Crew onboarding** (`⚡onboarding.blade.php`):
- Crew onboarding does NOT have a "complete profile" step like agents do
- The crew's name, company, phone are stored on User/CrewProfile but there's no onboarding step that displays/edits them
- Pre-population is automatic for insurance/W9/workers-comp metadata (read from CrewProfile in `mount()`)
- **No changes needed** to crew onboarding component — the profile data from quick-add is stored but not displayed as an editable step

**Agent `needs_onboarding = false` skip**:
- The `accept-invitation` component always redirects to the type's dashboard route (e.g., `agent.dashboard`)
- The **middleware** (`EnsureAgentOnboarded`) checks `needs_onboarding` and redirects to onboarding if true
- When `needs_onboarding = false` (full-form tier), the middleware allows through to the dashboard
- The modified `acceptInvitation()` preserves `needs_onboarding` for pre-existing users, so this works correctly

## Files Changed

### Modified:
1. `app/Services/InvitationService.php` — add `existingUserId` parameter to `sendInvitation()`, modify `acceptInvitation()` to handle pre-existing users
2. `resources/views/components/communications/⚡quick-add-crew.blade.php` — fix User creation, add SMS dispatch, use `existingUserId` for invitation
3. `resources/views/components/communications/⚡quick-add-agent.blade.php` — fix User creation, add listing fields, two-tier logic, SMS dispatch, Property/Deal/Estimate creation
4. `resources/views/components/communications/⚡quick-add-customer.blade.php` — remove invite email, add CustomerPreviewNotification, ensure ProjectPreview exists
5. `resources/views/components/invitations/⚡send-invitation.blade.php` — customer type sends preview email instead of invite, with customer/deal/preview lookup
6. `resources/views/components/agent/⚡onboarding.blade.php` — add pre-population of profile fields from AgentProfile in `mount()`

### Created:
7. `app/Notifications/CustomerPreviewNotification.php` — email with preview page link
8. Migration: add `existing_user_id` nullable FK to `invitations` table

### Tests:
9. Tests for `InvitationService` — sendInvitation with existingUserId, acceptInvitation for pre-existing user
10. Tests for crew quick-add SMS dispatch
11. Tests for agent two-tier logic (top only vs full form, Property/Deal/Estimate creation)
12. Tests for customer quick-add (no invite email, preview notification sent)
13. Tests for send-invitation customer flow change (preview email, edge cases)
14. Tests for agent onboarding pre-population and `needs_onboarding = false` skip

## Trade-offs

- **No workflow engine**: We chose direct code changes over workflow-based orchestration. The flows are well-defined and internal-facing. If configurability is needed later, the workflow engine is ready and these flows can be migrated.
- **Customer loses invite email entirely**: Customers access the system through their preview page. Account creation happens via preview page self-registration, estimate acceptance, or manual request.
- **Agent full form creates Property directly**: We bypass any property deduplication for agents. Since agents are adding new listings, duplicates are unlikely.
- **`existing_user_id` on Invitation**: Adds a column to the invitations table. This is a clean way to signal the accept flow without changing the invitation's core purpose. The column is nullable so existing invitations are unaffected.

## Out of Scope

- Workflow engine extensions (triggers, actions, dynamic values)
- Changes to the preview page self-registration flow
- Changes to the estimate acceptance → account creation flow
- Modifications to agent/crew onboarding step content (except agent profile pre-population)
- Password reset flow for customers who don't have an account yet

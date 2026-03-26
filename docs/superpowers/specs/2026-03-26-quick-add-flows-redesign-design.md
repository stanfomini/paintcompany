# Quick-Add Flows Redesign — Design Spec

**Date**: 2026-03-26
**Status**: Approved
**Scope**: Enhancement to communications hub quick-add forms + customer invitation flow change

## Problem

The communications hub has quick-add forms for creating customers, agents, and crew. The customer form is solid, but the agent and crew forms are bare-bones — they only create a user + empty profile and send an invite email. They don't send SMS, don't pre-populate onboarding data, and the agent form can't create deals/estimates. Additionally, customers currently receive invite emails that force account creation before they can view their estimate — they should instead receive a preview page link.

## Goals

1. Crew quick-add sends SMS with invite link (in addition to email)
2. Agent quick-add has two tiers: basic (profile only) and full (profile + listing → deal + estimate)
3. Full agent form skips onboarding entirely (`needs_onboarding = false`)
4. Customer flow stops sending invite emails — sends preview page link via email and SMS instead
5. This change applies globally: both quick-add forms AND the Send Invitation page

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

### Crew Quick-Add (`⚡quick-add-crew.blade.php`)

**Current behavior**: Creates User (type: crew) + CrewProfile, sends invite email.

**New behavior**:
- Create User (type: crew) + CrewProfile pre-populated with name, company_name, phone
- `needs_onboarding = true` (crew still goes through onboarding)
- Send invite email (existing behavior)
- **NEW**: Send SMS with invite link after user creation

**Crew experience**: Gets text + email with invite link → clicks it → sets password → lands on onboarding → sees name, company, phone already filled in → completes contracts, documents, etc.

**Implementation**:
- After `InvitationService::sendInvitation()`, dispatch SMS with the invitation URL
- Use `TwilioService` to send SMS with invite link if phone is provided
- The invitation URL is available from the `Invitation` model returned by `sendInvitation()`

### Agent Quick-Add (`⚡quick-add-agent.blade.php`)

**Current behavior**: Creates User (type: agent) + AgentProfile with company_name and phone, sends invite email.

**New behavior — two tiers**:

**Tier 1: Top only** (name, email, phone, company):
- Create User (type: agent) + AgentProfile pre-populated with company_name, phone
- `needs_onboarding = true`
- Send invite email + SMS with invite link
- Agent clicks link → sets password → goes through onboarding (profile fields pre-filled)

**Tier 2: Full form** (top + listing details):
- Create User + AgentProfile pre-populated
- Create Property (address, city, state, zip)
- Create Deal (title: "Painting Estimate - {name}", stage: lead, lead_source: communications_hub, linked to property)
- Create draft Estimate (linked to deal)
- `needs_onboarding = false`
- Send invite email + SMS with invite link
- Agent clicks link → sets password → goes straight to dashboard with deal + estimate waiting
- Notify internal users via `AgentListingSubmitted` notification (same as onboarding Step 2)

**Tier determination**: If `address` AND `city` are filled in, use Tier 2 (full form). Otherwise Tier 1.

**New form fields** (expandable/collapsible section below existing fields):
- Address (string)
- City (string)
- State (string)
- Zip (string)
- Listing Price (nullable, numeric)
- Intent: Selling or Buying (radio buttons)

**Implementation**:
- Add expandable listing section to the form template
- In `submitQuickAdd()`, check if address + city are provided to determine tier
- If full form: create Property, Deal, Estimate in the DB transaction, set `needs_onboarding = false` on the User
- After invitation, dispatch SMS with invite link if phone is provided
- The `accept-invitation` component already checks `needs_onboarding` and routes accordingly

### Customer Quick-Add (`⚡quick-add-customer.blade.php`)

**Current behavior**: Creates Customer + Deal + Estimate via LeadDeduplicationService, sends invite email via InvitationService, welcome SMS dispatched by model hooks.

**Changes**:
- **Remove** the `InvitationService::sendInvitation()` call — no more invite email
- **Add** a new preview page email notification (`CustomerPreviewNotification`) that sends the customer their preview page link
- Welcome SMS with preview link already happens via `SendWelcomeSmsJob` triggered by Customer/Deal creation — no change needed there

### Send Invitation Page (`⚡send-invitation.blade.php`)

**Current behavior**: Sends invite email for all user types (customer, agent, crew, internal).

**Changes**:
- When `invitedUserType === 'customer'`: instead of creating an Invitation and sending the invite email, find/create the customer's ProjectPreview and send the `CustomerPreviewNotification` with the preview page link
- When `invitedUserType === 'agent'` or `'crew'` or `'internal'`: existing invite email behavior unchanged

### New Notification: `CustomerPreviewNotification`

**Purpose**: Email notification that sends a customer their preview page URL instead of an account creation invite link.

**Email content**:
- Subject: "Your Project Preview from {company_name}"
- Body: Greeting with customer name, brief message about their project preview being ready, CTA button linking to the preview page URL
- Uses the `ProjectPreview::getPortalUrl()` for the link

**Triggered from**:
- Customer quick-add form (after creating customer + deal + estimate)
- Send Invitation page (when type is customer)

### SMS Details

**Agent/Crew SMS** (invite link):
- Content: "Hi {name}! You've been invited to join {company_name}. Set up your account here: {invite_link}"
- Sent after `InvitationService::sendInvitation()` returns the Invitation model
- Use `Invitation::getAcceptUrl()` or build from the invitation token
- Only sent if phone number is provided

**Customer SMS** (preview link):
- Already handled by `SendWelcomeSmsJob` — no changes needed
- Content uses `CompanySetting->welcome_sms_with_deal` template which includes `{portal_link}`

### Onboarding Pre-Population

**Agent onboarding** (`⚡onboarding.blade.php`):
- Step 1 (Complete Profile) fields: company_name, phone, license_number
- These are read from `AgentProfile` in `mount()` — since the quick-add form already populates `AgentProfile`, the fields will automatically be pre-filled
- No changes needed to the onboarding component itself

**Crew onboarding** (`⚡onboarding.blade.php`):
- Profile fields (company_name, phone) are read from `CrewProfile` in `mount()`
- Same as agent — pre-filled automatically from quick-add data
- No changes needed to the onboarding component itself

**Agent `needs_onboarding = false` skip**:
- The `accept-invitation` component already sets `needs_onboarding` from `InvitationService::acceptInvitation()`
- We need to ensure that when the quick-add sets `needs_onboarding = false` on the User, the accept flow respects this and routes to dashboard instead of onboarding
- Check: `acceptInvitation()` currently always sets `needs_onboarding = true` for agents — this needs to be changed to NOT override if already set to false

## Files Changed

### Modified:
1. `resources/views/components/communications/⚡quick-add-crew.blade.php` — add SMS dispatch
2. `resources/views/components/communications/⚡quick-add-agent.blade.php` — add listing fields, two-tier logic, SMS dispatch, Property/Deal/Estimate creation
3. `resources/views/components/communications/⚡quick-add-customer.blade.php` — remove invite email, add preview email notification
4. `resources/views/components/invitations/⚡send-invitation.blade.php` — customer type sends preview email instead of invite
5. `app/Services/InvitationService.php` — acceptInvitation() must not override `needs_onboarding = false` if already set

### Created:
6. `app/Notifications/CustomerPreviewNotification.php` — email with preview page link

### Tests:
7. Tests for crew SMS dispatch
8. Tests for agent two-tier logic (top only vs full form)
9. Tests for customer preview email (no invite email)
10. Tests for send-invitation customer flow change
11. Tests for agent onboarding skip when `needs_onboarding = false`

## Trade-offs

- **No workflow engine**: We chose direct code changes over workflow-based orchestration. The flows are well-defined and internal-facing. If configurability is needed later, the workflow engine is ready and these flows can be migrated.
- **Customer loses invite email entirely**: Customers can only create accounts through the preview page, estimate acceptance, or manual request. This simplifies the customer experience but means they can't log in until one of those events happens.
- **Agent full form creates Property directly**: We bypass any property deduplication for agents. Since agents are adding new listings, duplicates are unlikely.

## Out of Scope

- Workflow engine extensions (triggers, actions, dynamic values)
- Changes to the preview page self-registration flow
- Changes to the estimate acceptance → account creation flow
- Modifications to agent/crew onboarding step content

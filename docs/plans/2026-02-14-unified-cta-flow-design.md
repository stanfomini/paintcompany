# Unified Landing Page CTA Flow

## Problem
8 CTAs on the landing page with 3 different form experiences. The scarcity modal auto-creates accounts with random passwords and skips onboarding. The main form sends an email and redirects to a "next steps" page. Data collected upfront gets lost in onboarding.

## Solution
All customer CTAs create an invitation and redirect directly to the accept-invitation page. Same flow as clicking the email invite link, but instant. Email still sent as backup.

## Flow
```
Any CTA Form → Create Invitation → Redirect to /invitation/accept/{token}
→ Set Name + Password → 4-Step Onboarding (prefilled) → Dashboard
```

## Changes

### 1. Landing Page `submitLeadForm()`
- Store collected data in session key `landing_page_prefill`
- Create invitation, generate signed accept URL
- Redirect to accept URL directly (not `/next-steps`)
- Still send invitation email as backup

### 2. Landing Page `submitScarcityLead()`
- Remove auto-account creation with random password
- Create Customer record via ReferralService (minimal data)
- Create invitation, generate signed accept URL
- Store email + preference in session
- Redirect to accept URL directly

### 3. Accept Invitation `mount()`
- Check session for `landing_page_prefill` data
- Prefill name field if available

### 4. Customer Onboarding `mount()`
- Check session for `landing_page_prefill` data
- Prefill: phone, address, city, state, zip, estimatePreference, projectNotes
- Session data fills gaps not already covered by existing customer data

### Edge Cases
- Email already has account → redirect to customer login
- Email has pending invitation → reuse existing token
- Signed URL validation handled by Laravel

### Unchanged
- Accept-invitation UI template
- Onboarding UI template
- Agent modal flow
- Calculator links
- Anchor CTAs (scroll to form)

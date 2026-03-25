# Inbound Call Live Monitoring Fix

**Date**: 2026-03-24
**Status**: Approved
**Scope**: Bug fix + minor enhancement

## Problem

When an inbound call is answered by a team member on their cell phone, the CRM's active call bar (`⚡active-call.blade.php`) never appears. The user cannot hang up or transfer the call from the CRM UI. This works correctly for outbound calls but is broken for inbound calls.

### Root Cause

In `TwilioVoiceWebhookController::resolveAnsweringUser()` (line 573), the method attempts to identify who answered an inbound call using two Twilio callback parameters:

1. `DialBridged` — This is a **boolean** string (`'true'`/`'false'`), not a phone number
2. `Called` — This is the **Twilio company number**, not the user's forwarding number

Both values are useless for identifying the answering user. The method always returns `null`, so `CallConnected` is never broadcast for inbound calls, and the `⚡active-call` component never activates.

### Outbound Flow (Working)

```
User clicks dial → Twilio calls user's phone → User answers →
outboundConnect webhook → broadcasts CallConnected(caller_user_id) →
⚡active-call shows call bar with hangup/transfer
```

### Inbound Flow (Broken)

```
Caller dials in → Ring group TwiML dials team → User answers on cell →
dialStatus webhook → resolveAnsweringUser() returns null →
CallConnected NOT broadcast → ⚡active-call never activates
```

## Solution: Approach A — DialCallSid + Twilio REST API Lookup

### How It Works

When the `dialStatus` webhook fires with `DialCallStatus=completed` or `answered`:

1. Read the `DialCallSid` parameter (the SID of the child call leg that was answered)
2. Fetch the child call details from Twilio REST API: `$client->calls($dialCallSid)->fetch()`
3. The child call's `to` field contains the forwarding number that was dialed/answered
4. Match this forwarding number to a `User` record (same last-10-digits matching already used)
5. If Twilio API call fails, fall back to checking if the call group has exactly one active member
6. Broadcast `CallConnected` to the resolved user

### Broadcasting Scope

Only the answering user sees the live call bar (broadcasts to `private:call.user.{answeredByUserId}`). No changes to the `CallConnected` event or `⚡active-call` component needed.

## Files Changed

### 1. `app/Http/Controllers/TwilioVoiceWebhookController.php`

**`resolveAnsweringUser()`** — Replace broken logic:

```php
private function resolveAnsweringUser(Request $request, CallLog $callLog): ?int
{
    // Primary: Use DialCallSid to fetch the child call and get the answered number
    $dialCallSid = $request->input('DialCallSid');
    if ($dialCallSid) {
        try {
            $childCall = $this->twilio->getClient()->calls($dialCallSid)->fetch();
            if ($childCall->to) {
                $userId = $this->matchForwardingNumberToUser($childCall->to, $callLog->call_group_id);
                if ($userId) {
                    return $userId;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Twilio voice: failed to fetch child call for user resolution', [
                'dial_call_sid' => $dialCallSid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // Fallback: If call group has exactly one active member, it must be them
    if ($callLog->call_group_id) {
        $members = $callLog->callGroup?->members()
            ->where('is_active', true)
            ->whereNotNull('user_id')
            ->pluck('user_id');

        if ($members && $members->count() === 1) {
            return $members->first();
        }
    }

    return null;
}
```

**New helper `matchForwardingNumberToUser()`**:

Note: `CallGroupMember` can have a per-group `forwarding_number` override that differs from `User.forwarding_number`. The lookup checks both `call_group_members` (using the call's `call_group_id` context) and `users` to handle overrides.

```php
private function matchForwardingNumberToUser(string $phoneNumber, ?int $callGroupId = null): ?int
{
    $formatted = $this->twilio->formatE164($phoneNumber);
    if (! $formatted) {
        return null;
    }

    $digits = preg_replace('/\D/', '', $formatted);
    $last10 = substr($digits, -10);

    // Check call group member overrides first (more specific)
    if ($callGroupId) {
        $member = \App\Models\CallGroupMember::where('call_group_id', $callGroupId)
            ->where('forwarding_number', 'LIKE', '%' . $last10)
            ->where('is_active', true)
            ->first();

        if ($member?->user_id) {
            return $member->user_id;
        }
    }

    // Fall back to user's default forwarding number
    return User::where('forwarding_number', 'LIKE', '%' . $last10)->value('id');
}
```

### 2. `tests/Feature/Voice/TwilioVoiceWebhookTest.php`

Add/update tests:
- Test that `CallConnected` is broadcast when an inbound call is answered (mock Twilio API returns child call with matching forwarding number)
- Test the single-member fallback works when Twilio API fails
- Test that when Twilio API succeeds but `to` number matches no user, the fallback is attempted (cascade path)
- Test that null is returned when neither method resolves a user (multi-member group, no match)
- Verify `answered_by_user_id` is correctly set on the `CallLog` record

## Trade-offs

- **One extra Twilio API call per answered inbound call** (~100ms). Negligible for a painting company's call volume (likely <50 calls/day).
- **No changes to TwiML structure**, events, broadcasting channels, or UI components.
- **Graceful degradation**: If both resolution methods fail, the call still works — the user just won't see the call bar in the CRM.

## Out of Scope

- Multi-user call monitoring (admins seeing all live calls) — not needed now
- Changes to the `⚡active-call` component — it already handles `CallConnected` correctly
- Changes to the `CallConnected` event — broadcasting scope stays single-user

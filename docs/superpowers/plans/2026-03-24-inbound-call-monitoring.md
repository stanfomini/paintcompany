# Inbound Call Live Monitoring Fix — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix the `resolveAnsweringUser()` bug so inbound calls show the active call bar with hangup/transfer controls.

**Architecture:** Replace broken `DialBridged`/`Called` lookup with `DialCallSid` → Twilio REST API → child call `to` field → match forwarding number to user. Fallback to single-member call group check.

**Tech Stack:** Laravel 12, Twilio REST API, Pest 4, Mockery

**Spec:** `docs/superpowers/specs/2026-03-24-inbound-call-monitoring-design.md`

---

### Task 1: Write failing tests for inbound call user resolution

**Files:**
- Modify: `tests/Feature/Voice/TwilioVoiceWebhookTest.php` (add tests in `dial status webhook` describe block)

The existing test "updates call log when dial is answered" (line 258) passes today because it only checks status/answered_at — it doesn't verify `answered_by_user_id` or `CallConnected` broadcasting. We need tests that specifically verify user resolution and event broadcasting.

- [ ] **Step 0: Add missing imports to the test file**

Add these `use` statements to the top of `tests/Feature/Voice/TwilioVoiceWebhookTest.php` (after the existing imports):

```php
use App\Models\User;
```

Note: `CallGroup` and `CallGroupMember` are referenced via FQCNs (`\App\Models\CallGroup::factory()`, `\App\Models\CallGroupMember::create()`) in the test code below, matching the style used in other test files. `Mockery` is globally available in Pest and does not need an import.

- [ ] **Step 1: Add test — CallConnected broadcast when inbound call answered via Twilio API lookup**

Add this test inside the existing `describe('dial status webhook', ...)` block, after the existing tests:

```php
it('broadcasts CallConnected when inbound call is answered and user resolved via Twilio API', function () {
    Event::fake([CallConnected::class]);

    $user = User::factory()->internal()->create([
        'forwarding_number' => '+13035551111',
        'extension' => '100',
    ]);

    $callGroup = \App\Models\CallGroup::factory()->create();
    \App\Models\CallGroupMember::create([
        'call_group_id' => $callGroup->id,
        'user_id' => $user->id,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $callLog = CallLog::factory()->create([
        'status' => 'ringing',
        'answered_at' => null,
        'direction' => 'inbound',
        'call_group_id' => $callGroup->id,
    ]);

    // Mock Twilio client to return child call with the user's forwarding number
    $childCallInstance = Mockery::mock(\Twilio\Rest\Api\V2010\Account\CallInstance::class);
    $childCallInstance->to = '+13035551111';

    $childCallContext = Mockery::mock(\Twilio\Rest\Api\V2010\Account\CallContext::class);
    $childCallContext->shouldReceive('fetch')->once()->andReturn($childCallInstance);

    $client = Mockery::mock(\Twilio\Rest\Client::class);
    $client->shouldReceive('calls')
        ->once()
        ->with('CAchild123abc')
        ->andReturn($childCallContext);

    $this->twilioMock->shouldReceive('getClient')->andReturn($client);
    $this->twilioMock->shouldReceive('formatE164')
        ->with('+13035551111')
        ->andReturn('+13035551111');

    $response = $this->post("/webhooks/twilio/voice/dial-status/{$callLog->ulid}", [
        'DialCallStatus' => 'completed',
        'DialCallSid' => 'CAchild123abc',
    ]);

    $response->assertStatus(200);

    $callLog->refresh();
    expect($callLog->status)->toBe('in-progress')
        ->and($callLog->answered_by_user_id)->toBe($user->id)
        ->and($callLog->answered_at)->not->toBeNull();

    Event::assertDispatched(CallConnected::class, function ($event) use ($callLog, $user) {
        return $event->callLog->id === $callLog->id
            && $event->answeredByUserId === $user->id;
    });
});
```

- [ ] **Step 2: Add test — fallback to single-member call group when Twilio API fails**

```php
it('falls back to single call group member when Twilio API fails', function () {
    Event::fake([CallConnected::class]);

    $user = User::factory()->internal()->create([
        'forwarding_number' => '+13035552222',
        'extension' => '101',
    ]);

    $callGroup = \App\Models\CallGroup::factory()->create();
    \App\Models\CallGroupMember::create([
        'call_group_id' => $callGroup->id,
        'user_id' => $user->id,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $callLog = CallLog::factory()->create([
        'status' => 'ringing',
        'answered_at' => null,
        'direction' => 'inbound',
        'call_group_id' => $callGroup->id,
    ]);

    // Mock Twilio client to throw exception (API failure)
    $client = Mockery::mock(\Twilio\Rest\Client::class);
    $client->shouldReceive('calls')
        ->once()
        ->andThrow(new \Exception('Twilio API error'));

    $this->twilioMock->shouldReceive('getClient')->andReturn($client);

    $response = $this->post("/webhooks/twilio/voice/dial-status/{$callLog->ulid}", [
        'DialCallStatus' => 'completed',
        'DialCallSid' => 'CAchildfail',
    ]);

    $response->assertStatus(200);

    $callLog->refresh();
    expect($callLog->answered_by_user_id)->toBe($user->id);

    Event::assertDispatched(CallConnected::class, function ($event) use ($user) {
        return $event->answeredByUserId === $user->id;
    });
});
```

- [ ] **Step 3: Add test — cascade: API succeeds but number doesn't match, then fallback tried**

```php
it('tries call group fallback when Twilio API returns unrecognized number', function () {
    Event::fake([CallConnected::class]);

    $user = User::factory()->internal()->create([
        'forwarding_number' => '+13035553333',
        'extension' => '102',
    ]);

    $callGroup = \App\Models\CallGroup::factory()->create();
    \App\Models\CallGroupMember::create([
        'call_group_id' => $callGroup->id,
        'user_id' => $user->id,
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $callLog = CallLog::factory()->create([
        'status' => 'ringing',
        'answered_at' => null,
        'direction' => 'inbound',
        'call_group_id' => $callGroup->id,
    ]);

    // Mock Twilio client to return a number that doesn't match any user
    $childCallInstance = Mockery::mock(\Twilio\Rest\Api\V2010\Account\CallInstance::class);
    $childCallInstance->to = '+19995550000';

    $childCallContext = Mockery::mock(\Twilio\Rest\Api\V2010\Account\CallContext::class);
    $childCallContext->shouldReceive('fetch')->once()->andReturn($childCallInstance);

    $client = Mockery::mock(\Twilio\Rest\Client::class);
    $client->shouldReceive('calls')
        ->once()
        ->with('CAchildunknown')
        ->andReturn($childCallContext);

    $this->twilioMock->shouldReceive('getClient')->andReturn($client);
    $this->twilioMock->shouldReceive('formatE164')
        ->with('+19995550000')
        ->andReturn('+19995550000');

    $response = $this->post("/webhooks/twilio/voice/dial-status/{$callLog->ulid}", [
        'DialCallStatus' => 'completed',
        'DialCallSid' => 'CAchildunknown',
    ]);

    $response->assertStatus(200);

    $callLog->refresh();
    // Should fall back to single-member group
    expect($callLog->answered_by_user_id)->toBe($user->id);

    Event::assertDispatched(CallConnected::class);
});
```

- [ ] **Step 4: Add test — no resolution when multi-member group and API fails**

```php
it('does not broadcast CallConnected when user cannot be resolved in multi-member group', function () {
    Event::fake([CallConnected::class]);

    $user1 = User::factory()->internal()->create(['forwarding_number' => '+13035554444']);
    $user2 = User::factory()->internal()->create(['forwarding_number' => '+13035555555']);

    $callGroup = \App\Models\CallGroup::factory()->create();
    \App\Models\CallGroupMember::create([
        'call_group_id' => $callGroup->id,
        'user_id' => $user1->id,
        'is_active' => true,
        'sort_order' => 0,
    ]);
    \App\Models\CallGroupMember::create([
        'call_group_id' => $callGroup->id,
        'user_id' => $user2->id,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $callLog = CallLog::factory()->create([
        'status' => 'ringing',
        'answered_at' => null,
        'direction' => 'inbound',
        'call_group_id' => $callGroup->id,
    ]);

    // Mock Twilio client to throw (simulating API failure)
    $client = Mockery::mock(\Twilio\Rest\Client::class);
    $client->shouldReceive('calls')
        ->once()
        ->andThrow(new \Exception('Twilio API error'));

    $this->twilioMock->shouldReceive('getClient')->andReturn($client);

    $response = $this->post("/webhooks/twilio/voice/dial-status/{$callLog->ulid}", [
        'DialCallStatus' => 'completed',
        'DialCallSid' => 'CAchildmulti',
    ]);

    $response->assertStatus(200);

    $callLog->refresh();
    expect($callLog->status)->toBe('in-progress')
        ->and($callLog->answered_by_user_id)->toBeNull();

    Event::assertNotDispatched(CallConnected::class);
});
```

- [ ] **Step 5: Add test — resolves via CallGroupMember forwarding_number override**

```php
it('resolves user via call group member forwarding number override', function () {
    Event::fake([CallConnected::class]);

    $user = User::factory()->internal()->create([
        'forwarding_number' => '+13035556666',
        'extension' => '103',
    ]);

    $callGroup = \App\Models\CallGroup::factory()->create();
    // Member has a different forwarding number override
    \App\Models\CallGroupMember::create([
        'call_group_id' => $callGroup->id,
        'user_id' => $user->id,
        'forwarding_number' => '+13035557777',
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $callLog = CallLog::factory()->create([
        'status' => 'ringing',
        'answered_at' => null,
        'direction' => 'inbound',
        'call_group_id' => $callGroup->id,
    ]);

    // Twilio child call returns the override number, not the user's default
    $childCallInstance = Mockery::mock(\Twilio\Rest\Api\V2010\Account\CallInstance::class);
    $childCallInstance->to = '+13035557777';

    $childCallContext = Mockery::mock(\Twilio\Rest\Api\V2010\Account\CallContext::class);
    $childCallContext->shouldReceive('fetch')->once()->andReturn($childCallInstance);

    $client = Mockery::mock(\Twilio\Rest\Client::class);
    $client->shouldReceive('calls')
        ->once()
        ->with('CAchildoverride')
        ->andReturn($childCallContext);

    $this->twilioMock->shouldReceive('getClient')->andReturn($client);
    $this->twilioMock->shouldReceive('formatE164')
        ->with('+13035557777')
        ->andReturn('+13035557777');

    $response = $this->post("/webhooks/twilio/voice/dial-status/{$callLog->ulid}", [
        'DialCallStatus' => 'completed',
        'DialCallSid' => 'CAchildoverride',
    ]);

    $response->assertStatus(200);

    $callLog->refresh();
    expect($callLog->answered_by_user_id)->toBe($user->id);

    Event::assertDispatched(CallConnected::class, function ($event) use ($user) {
        return $event->answeredByUserId === $user->id;
    });
});
```

- [ ] **Step 6: Run tests to verify they all fail**

Run: `php artisan test --compact tests/Feature/Voice/TwilioVoiceWebhookTest.php --filter="broadcasts CallConnected|falls back to single|tries call group fallback|does not broadcast CallConnected|resolves user via call group"`

Expected: All 5 new tests FAIL (resolveAnsweringUser returns null for all cases)

- [ ] **Step 7: Commit failing tests**

```bash
git add tests/Feature/Voice/TwilioVoiceWebhookTest.php
git commit -m "test: add failing tests for inbound call user resolution

Tests verify CallConnected broadcasting, Twilio API lookup,
call group fallback, cascade path, and member override scenarios.
All fail due to broken resolveAnsweringUser()."
```

---

### Task 2: Fix resolveAnsweringUser and add matchForwardingNumberToUser helper

**Files:**
- Modify: `app/Http/Controllers/TwilioVoiceWebhookController.php:573-592` (replace `resolveAnsweringUser`, add `matchForwardingNumberToUser`)

- [ ] **Step 1: Replace `resolveAnsweringUser()` method**

Replace the existing `resolveAnsweringUser` method (lines 573-592) with the fixed version:

```php
/**
 * Try to resolve which user answered the call.
 */
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
                'call_log_id' => $callLog->id,
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

- [ ] **Step 2: Add `matchForwardingNumberToUser()` helper method**

Add this new private method right after `resolveAnsweringUser`:

```php
/**
 * Match a phone number to a user by their forwarding number.
 * Checks call group member overrides first, then user defaults.
 */
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
            ->where('forwarding_number', 'LIKE', '%'.$last10)
            ->where('is_active', true)
            ->first();

        if ($member?->user_id) {
            return $member->user_id;
        }
    }

    // Fall back to user's default forwarding number
    return \App\Models\User::where('forwarding_number', 'LIKE', '%'.$last10)->value('id');
}
```

- [ ] **Step 3: Run all tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Voice/TwilioVoiceWebhookTest.php`

Expected: ALL tests pass (existing + 5 new)

- [ ] **Step 4: Run Pint to fix formatting**

Run: `vendor/bin/pint --dirty`

- [ ] **Step 5: Commit the fix**

```bash
git add app/Http/Controllers/TwilioVoiceWebhookController.php
git commit -m "fix: resolve answering user for inbound calls via DialCallSid

Previously resolveAnsweringUser() checked DialBridged (a boolean)
and Called (the Twilio number), so it always returned null for
inbound calls. Now uses DialCallSid to fetch the child call from
Twilio REST API and match the forwarding number to a user.

Includes call group member override support and single-member
fallback when the API call fails."
```

---

### Task 3: Run full voice test suite and verify no regressions

**Files:**
- Read-only verification pass

- [ ] **Step 1: Run all voice tests**

Run: `php artisan test --compact tests/Feature/Voice/`

Expected: ALL tests pass with no regressions

- [ ] **Step 2: Run the active call overlay tests (related UI tests)**

Run: `php artisan test --compact tests/Feature/Voice/ActiveCallOverlayTest.php`

Expected: ALL tests pass

- [ ] **Step 3: Commit (only if any formatting/fixup needed)**

Only commit if Pint or fixups were needed. Otherwise, skip.

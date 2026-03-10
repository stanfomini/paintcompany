# Messenger Duplicate Prevention & Conversation Cleanup

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Stop the messenger bot from creating duplicate customers, deals, and orphaned conversations when the same person messages multiple times.

**Architecture:** Three-layer fix: (1) recognize returning users by Facebook PSID and skip re-running the bot, (2) PSID-based customer lookup as fallback when bot does create a lead, (3) deduplicate messenger contacts in the conversations hub so the same person doesn't appear twice.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Pest 4

---

## Root Cause Summary

When a Facebook user messages again after completing a bot flow:
1. `handleMessage()` checks for `inProgress()` conversation — finds none (previous is `completed`)
2. Calls `startConversation()` which **always** creates a new `MessengerConversation`
3. Bot completes → `createFromConversation()` runs
4. `findExistingCustomer()` only checks email/phone — if bot didn't collect those, creates **new Customer**
5. Always creates a **new Deal** regardless of whether customer already has one
6. Result: same person = N customers + N deals + N conversations in the hub

---

### Task 1: Recognize returning users — skip bot for known customers

**Files:**
- Modify: `app/Services/FacebookMessengerService.php:176-219` (handleMessage)
- Modify: `app/Services/FacebookMessengerService.php:224-259` (processPostback)
- Test: `tests/Feature/Messenger/MessengerReturningUserTest.php` (new)

**Context:** When a PSID has a completed conversation linked to a customer, the person is already a lead. Don't re-run the bot — just store the message in the existing thread for human follow-up.

**Step 1: Write the failing test**

Create `tests/Feature/Messenger/MessengerReturningUserTest.php`:

```php
<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Services\FacebookMessengerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    MessengerSetting::clearInstanceCache();
    $this->assignedUser = User::factory()->internal()->create();
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'default_assigned_to' => $this->assignedUser->id,
        'page_access_token' => 'test-token',
    ]);
    MessengerSetting::clearInstanceCache();

    Http::fake(['*' => Http::response(['message_id' => 'mid.test'])]);
});

afterEach(fn () => MessengerSetting::clearInstanceCache());

it('does not start new bot flow when PSID has completed conversation with customer', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $thread = ChatThread::factory()->facebook()->create([
        'external_contact_id' => 'PSID_RETURNING',
        'customer_id' => $customer->id,
    ]);

    // Previous completed conversation linked to customer
    MessengerConversation::factory()->completed()->create([
        'facebook_psid' => 'PSID_RETURNING',
        'customer_id' => $customer->id,
        'thread_id' => $thread->id,
    ]);

    // Active bot flow exists
    $flow = MessengerBotFlow::factory()->active()->create();
    MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $flow->id,
        'sort_order' => 0,
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'PSID_RETURNING'],
            'message' => ['text' => 'Hey I have another question', 'mid' => 'mid.return'],
        ]],
    ]);

    // Should NOT create a new MessengerConversation
    expect(MessengerConversation::where('facebook_psid', 'PSID_RETURNING')->count())->toBe(1);

    // Should store the message in existing thread
    expect(ChatMessage::where('thread_id', $thread->id)->count())->toBe(1);

    // Should NOT create a new customer or deal
    expect(Customer::count())->toBe(1);
    expect(Deal::count())->toBe(0);
});

it('does not start new bot flow for returning postback user', function () {
    Notification::fake();

    $customer = Customer::factory()->create();
    $thread = ChatThread::factory()->facebook()->create([
        'external_contact_id' => 'PSID_POSTBACK',
        'customer_id' => $customer->id,
    ]);

    MessengerConversation::factory()->completed()->create([
        'facebook_psid' => 'PSID_POSTBACK',
        'customer_id' => $customer->id,
        'thread_id' => $thread->id,
    ]);

    $flow = MessengerBotFlow::factory()->active()->create();
    MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $flow->id,
        'sort_order' => 0,
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'PSID_POSTBACK'],
            'postback' => ['payload' => 'GET_STARTED', 'title' => 'Get Started'],
        ]],
    ]);

    expect(MessengerConversation::where('facebook_psid', 'PSID_POSTBACK')->count())->toBe(1);
});

it('still starts bot flow for brand new PSID', function () {
    Notification::fake();

    $flow = MessengerBotFlow::factory()->active()->create();
    MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $flow->id,
        'sort_order' => 0,
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'PSID_BRAND_NEW'],
            'message' => ['text' => 'Hi there', 'mid' => 'mid.new'],
        ]],
    ]);

    expect(MessengerConversation::where('facebook_psid', 'PSID_BRAND_NEW')->count())->toBe(1);
});

it('still starts bot flow for PSID with completed conversation but no customer', function () {
    Notification::fake();

    $thread = ChatThread::factory()->facebook()->create([
        'external_contact_id' => 'PSID_NO_CUSTOMER',
    ]);

    // Previous conversation completed but never linked to customer (abandoned before lead creation?)
    MessengerConversation::factory()->completed()->create([
        'facebook_psid' => 'PSID_NO_CUSTOMER',
        'customer_id' => null,
        'thread_id' => $thread->id,
    ]);

    $flow = MessengerBotFlow::factory()->active()->create();
    MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $flow->id,
        'sort_order' => 0,
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'PSID_NO_CUSTOMER'],
            'message' => ['text' => 'Hello', 'mid' => 'mid.nocust'],
        ]],
    ]);

    // Should start a new bot conversation since previous had no customer
    expect(MessengerConversation::where('facebook_psid', 'PSID_NO_CUSTOMER')->count())->toBe(2);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Messenger/MessengerReturningUserTest.php`
Expected: First two tests FAIL (bot flow starts anyway), last two PASS.

**Step 3: Implement returning user check**

In `app/Services/FacebookMessengerService.php`, add a helper method and modify `handleMessage()` and `processPostback()`:

Add this private method to the class:

```php
/**
 * Check if this PSID belongs to a returning user who already has a customer record.
 */
protected function isReturningCustomer(string $psid): bool
{
    return MessengerConversation::where('facebook_psid', $psid)
        ->whereNotNull('customer_id')
        ->exists();
}
```

In `handleMessage()`, after the `inProgress` check (line 206-214), before calling `startConversation` (line 218), add the returning user check:

```php
// After line 214 (return from inProgress block), before line 216:

// Returning customer — skip bot, let message sit for human follow-up
if ($this->isReturningCustomer($psid)) {
    return;
}
```

In `processPostback()`, same pattern — after the `inProgress` check (line 246-254), before calling `startConversation` (line 258):

```php
// After line 254 (return from inProgress block), before line 256:

// Returning customer — skip bot, let message sit for human follow-up
if ($this->isReturningCustomer($psid)) {
    return;
}
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Messenger/MessengerReturningUserTest.php`
Expected: All 4 PASS.

**Step 5: Run existing messenger tests to confirm no regressions**

Run: `php artisan test --compact tests/Feature/Messenger/`
Expected: All pass.

**Step 6: Commit**

```
feat: skip bot flow for returning messenger users with existing customer

When a Facebook user who already completed a bot flow and has a linked
customer messages again, the message is stored in the existing thread
for human follow-up instead of re-running the bot.
```

---

### Task 2: PSID-based customer lookup as fallback

**Files:**
- Modify: `app/Services/MessengerLeadCreationService.php:281-305` (findExistingCustomer)
- Modify: `app/Services/MessengerLeadCreationService.php:23` (createFromConversation signature context)
- Test: `tests/Feature/Messenger/MessengerLeadCreationServiceTest.php` (add tests)

**Context:** Even with Task 1, edge cases exist (ad-triggered flows, manual conversion). When `findExistingCustomer()` runs, it should also check if any previous MessengerConversation with the same PSID already has a customer. This is the safety net.

**Step 1: Write the failing tests**

Add to `tests/Feature/Messenger/MessengerLeadCreationServiceTest.php` inside the `createFromConversation` describe block:

```php
it('finds existing customer by PSID match from previous conversation', function () {
    Notification::fake();

    $existingCustomer = Customer::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Messenger',
    ]);

    // Previous conversation linked this PSID to the customer
    $thread = ChatThread::factory()->facebook()->create(['external_contact_id' => 'PSID_JANE']);
    MessengerConversation::factory()->completed()->create([
        'facebook_psid' => 'PSID_JANE',
        'customer_id' => $existingCustomer->id,
        'thread_id' => $thread->id,
    ]);

    // New conversation from same PSID, no email/phone collected
    $flow = createFlowWithSteps(['customer.first_name']);
    $newConversation = createConversationWithAnswers($flow, ['Jane']);
    $newConversation->update(['facebook_psid' => 'PSID_JANE']);

    $service = new MessengerLeadCreationService;
    $result = $service->createFromConversation($newConversation);

    expect($result['is_new_customer'])->toBeFalse();
    expect($result['customer']->id)->toBe($existingCustomer->id);
    expect(Customer::count())->toBe(1);
});

it('does not create duplicate deal when returning customer already has active messenger deal', function () {
    Notification::fake();

    $existingCustomer = Customer::factory()->create(['email' => 'returning-deal@test.com']);
    $existingDeal = Deal::factory()->create([
        'customer_id' => $existingCustomer->id,
        'stage' => 'lead',
        'lead_source' => 'Facebook Messenger',
    ]);

    // Previous conversation linked
    $thread = ChatThread::factory()->facebook()->create(['external_contact_id' => 'PSID_DEAL']);
    MessengerConversation::factory()->completed()->create([
        'facebook_psid' => 'PSID_DEAL',
        'customer_id' => $existingCustomer->id,
        'deal_id' => $existingDeal->id,
        'thread_id' => $thread->id,
    ]);

    // New conversation same PSID
    $flow = createFlowWithSteps(['customer.email']);
    $newConversation = createConversationWithAnswers($flow, ['returning-deal@test.com']);
    $newConversation->update(['facebook_psid' => 'PSID_DEAL']);

    $service = new MessengerLeadCreationService;
    $result = $service->createFromConversation($newConversation);

    // Should reuse existing deal, not create a new one
    expect($result['deal']->id)->toBe($existingDeal->id);
    expect(Deal::where('customer_id', $existingCustomer->id)->count())->toBe(1);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="finds existing customer by PSID|does not create duplicate deal"`
Expected: Both FAIL.

**Step 3: Implement PSID-based lookup**

In `app/Services/MessengerLeadCreationService.php`, modify `findExistingCustomer()` to accept an optional PSID and check it first:

```php
/**
 * Find existing customer by PSID, email, or phone match.
 */
private function findExistingCustomer(array $customerData, ?string $psid = null): ?Customer
{
    // Try PSID match first — most reliable for messenger
    if ($psid) {
        $existingConversation = MessengerConversation::where('facebook_psid', $psid)
            ->whereNotNull('customer_id')
            ->latest()
            ->first();

        if ($existingConversation?->customer_id) {
            return Customer::find($existingConversation->customer_id);
        }
    }

    // Try email match
    if (! empty($customerData['email'])) {
        $customer = Customer::where('email', $customerData['email'])->first();
        if ($customer) {
            return $customer;
        }
    }

    // Try phone match (normalized)
    if (! empty($customerData['phone'])) {
        $normalizedPhone = $this->normalizePhone($customerData['phone']);
        if ($normalizedPhone) {
            $customer = Customer::get()->first(function ($c) use ($normalizedPhone) {
                return $this->normalizePhone($c->phone ?? '') === $normalizedPhone;
            });
            if ($customer) {
                return $customer;
            }
        }
    }

    return null;
}
```

Then update the two callers to pass the PSID:

In `createFromConversation()` (around line 42):
```php
$customer = $this->findExistingCustomer($customerData, $conversation->facebook_psid);
```

In `createFromManualConversion()` (around line 165):
```php
$customer = $this->findExistingCustomer($customerData, $conversation->facebook_psid);
```

**Step 4: Add deal reuse logic**

In `createFromConversation()`, after finding the existing customer (around line 67 where deal creation begins), check for an existing active messenger deal:

```php
// Check for existing active messenger deal for this customer
$existingDeal = null;
if (! $isNewCustomer) {
    $existingDeal = Deal::where('customer_id', $customer->id)
        ->where('lead_source', 'Facebook Messenger')
        ->whereNotIn('stage', ['lost', 'completed'])
        ->latest()
        ->first();
}

if ($existingDeal) {
    $deal = $existingDeal;
    $estimate = $existingDeal->estimates()->latest()->first();
} else {
    // ... existing deal + estimate creation code ...
}
```

Make sure the conversation linking and notification logic still runs regardless.

**Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Messenger/MessengerLeadCreationServiceTest.php`
Expected: All pass (existing + new).

**Step 6: Commit**

```
feat: PSID-based customer lookup and deal reuse for messenger leads

Prevents duplicate customers by checking facebook_psid before email/phone.
Reuses existing active messenger deals instead of creating new ones.
```

---

### Task 3: Deduplicate messenger contacts in conversations hub

**Files:**
- Modify: `app/Services/CommunicationsContactService.php:313-356` (getMessengerContacts)
- Test: `tests/Feature/Communications/MessengerContactsTest.php` (add test)

**Context:** When a messenger thread has a `customer_id` AND that customer has a portal User account (appears in the main contacts list), the messenger thread shows as a separate contact. This creates duplicate entries. Fix: exclude messenger threads whose customer already appears in the main contacts.

**Step 1: Write the failing test**

Add to `tests/Feature/Communications/MessengerContactsTest.php` (or create if doesn't exist):

```php
it('excludes messenger contacts whose customer appears in main contacts', function () {
    $viewer = User::factory()->internal()->create();
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

    // Create a direct chat thread so customer appears in main contacts
    $directThread = ChatThread::factory()->create(['type' => 'direct']);
    $directThread->addParticipant($viewer);
    $directThread->addParticipant($customerUser);

    // Messenger thread linked to same customer
    $messengerThread = ChatThread::factory()->facebook()->create([
        'external_contact_id' => 'PSID_DEDUP',
        'customer_id' => $customer->id,
        'subject' => $customer->full_name,
    ]);
    $messengerThread->addParticipant($viewer);

    $service = new CommunicationsContactService;
    $messengerContacts = $service->getMessengerContacts($viewer);

    // Should NOT include the messenger contact since customer is in main contacts
    expect($messengerContacts->where('id', 'messenger-' . $messengerThread->id)->count())->toBe(0);
});

it('keeps messenger contacts with no linked customer', function () {
    $viewer = User::factory()->internal()->create();

    $messengerThread = ChatThread::factory()->facebook()->create([
        'external_contact_id' => 'PSID_STANDALONE',
        'customer_id' => null,
        'subject' => 'Unknown Messenger User',
    ]);
    $messengerThread->addParticipant($viewer);

    $service = new CommunicationsContactService;
    $messengerContacts = $service->getMessengerContacts($viewer);

    expect($messengerContacts->where('id', 'messenger-' . $messengerThread->id)->count())->toBe(1);
});
```

**Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Communications/MessengerContactsTest.php`
Expected: First test FAILS, second PASSES.

**Step 3: Implement deduplication**

In `app/Services/CommunicationsContactService.php`, modify `getMessengerContacts()` to filter out threads whose customer has a portal User account:

After fetching threads (around line 320), add a filter:

```php
// Exclude messenger threads whose customer already appears in main contacts (has portal user)
$threads = $threads->filter(function (ChatThread $thread) {
    if (! $thread->customer_id) {
        return true; // Keep standalone messenger contacts
    }

    // If customer has a portal User account, they appear in main contacts already
    $customer = Customer::find($thread->customer_id);

    return ! $customer?->user_id;
});
```

**Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Communications/MessengerContactsTest.php`
Expected: All pass.

**Step 5: Commit**

```
fix: deduplicate messenger contacts in conversations hub

Messenger threads linked to customers who have portal accounts no longer
appear as separate contacts — they are accessible via the customer contact.
```

---

### Task 4: Ensure messenger threads show under customer contact

**Files:**
- Modify: `resources/views/components/communications/⚡hub.blade.php` (messenger tab for customer contacts)
- Test: Manual verification (UI behavior)

**Context:** After Task 3, messenger threads for portal-linked customers won't show as standalone contacts. But the user needs to see those messenger conversations when clicking on the customer contact. The hub already has a "messenger" tab per contact — we need to ensure messenger threads linked to the customer's `customer_id` show up there.

**Step 1: Read the hub component to find the messenger tab logic**

Read: `resources/views/components/communications/⚡hub.blade.php` — find where per-contact messenger threads are loaded (likely in a computed property or method that filters by customer).

**Step 2: Verify messenger threads are included**

Check if `getSharedChatThreads()` or similar method already includes facebook-channel threads linked via `customer_id`. If the customer has a `ChatThread` with `channel = facebook`, it should appear in the messenger tab.

If the threads are loaded via `ChatParticipant` join only (no customer_id join), add a union query:

```php
// In the method that loads threads for a customer contact, ensure facebook threads are included:
$messengerThreads = ChatThread::where('channel', ChatThread::CHANNEL_FACEBOOK)
    ->where('customer_id', $customer->id)
    ->get();
```

Merge these into the existing threads collection for the contact.

**Step 3: Run existing hub tests**

Run: `php artisan test --compact tests/Feature/Communications/`
Expected: All pass.

**Step 4: Commit**

```
fix: show messenger threads in customer contact's conversation view
```

---

### Task 5: Duplicate flag indicator on leads kanban

**Files:**
- Modify: `resources/views/components/deals/⚡kanban.blade.php` (or whatever the kanban component is)
- Test: Manual verification

**Context:** When a deal's customer has pending duplicate flags, show a small amber indicator on the kanban card. The user should be able to see at a glance which leads might be duplicates.

**Step 1: Find the kanban component**

Search for: `resources/views/components/deals/⚡kanban.blade.php` or `⚡board.blade.php` or similar.

**Step 2: Add duplicate flag check**

In the kanban card rendering, add a computed check:

```php
// In the component class:
public function hasDuplicateFlag(int $dealId): bool
{
    static $flaggedCustomerIds = null;

    if ($flaggedCustomerIds === null) {
        $flaggedCustomerIds = \App\Models\CustomerDuplicateFlag::query()
            ->where('status', 'pending')
            ->pluck('customer_id')
            ->unique()
            ->all();
    }

    $deal = $this->deals->firstWhere('id', $dealId);

    return $deal && in_array($deal->customer_id, $flaggedCustomerIds);
}
```

**Step 3: Add amber badge to kanban card**

In the Blade template, inside each deal card:

```blade
@if($this->hasDuplicateFlag($deal->id))
    <span class="inline-flex items-center rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-400" title="Possible duplicate customer">
        Duplicate?
    </span>
@endif
```

**Step 4: Commit**

```
feat: show duplicate flag indicator on deals kanban cards
```

---

### Task 6: Run full test suite and cleanup

**Step 1: Run all messenger tests**

Run: `php artisan test --compact tests/Feature/Messenger/`
Expected: All pass.

**Step 2: Run all communications tests**

Run: `php artisan test --compact tests/Feature/Communications/`
Expected: All pass.

**Step 3: Run all duplicate/merge tests**

Run: `php artisan test --compact tests/Feature/CustomerDuplicateServiceTest.php tests/Feature/CustomerMergeServiceTest.php`
Expected: All pass.

**Step 4: Run pint**

Run: `vendor/bin/pint --dirty`

**Step 5: Final commit and push**

```
chore: lint and finalize messenger duplicate prevention
```

---

## Execution Notes

- **Tasks 1-2 are the critical fixes** — they stop the bleeding (no more duplicate customers/deals from messenger)
- **Task 3 cleans up the UI** — deduplicates the conversations tab
- **Task 4 ensures no data is lost** — messenger conversations are still accessible via customer contact
- **Task 5 is a nice-to-have** — visual indicator on kanban
- **Task 6 is validation** — full test sweep

## Key Files Reference

| File | Purpose |
|------|---------|
| `app/Services/FacebookMessengerService.php` | Message routing, thread creation |
| `app/Services/MessengerBotFlowService.php` | Bot conversation lifecycle |
| `app/Services/MessengerLeadCreationService.php` | Customer + Deal creation from bot |
| `app/Services/CommunicationsContactService.php` | Contacts list for hub |
| `app/Services/CustomerDuplicateService.php` | Duplicate detection logic |
| `app/Services/CustomerMergeService.php` | Customer merge execution |
| `app/Models/MessengerConversation.php` | Conversation model (has facebook_psid) |
| `app/Models/ChatThread.php` | Thread model (has external_contact_id = PSID) |
| `resources/views/components/communications/⚡hub.blade.php` | Conversations UI |

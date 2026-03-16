# Multimedia Bot Flows & Cross-Channel Media Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hardcoded SMS bot with a configurable step-driven flow engine, add keyword triggers to both SMS and Messenger, and bridge all media into the Communications Hub.

**Architecture:** Two sibling bot flow systems (SMS and Messenger) each with their own models and services but sharing the same step-driven pattern. Media from all channels flows through `ChatAttachment` for unified display in the Comms Hub. AI visualization uses existing `GenerateColorVisualizationJob` with a new callback path.

**Tech Stack:** Laravel 12, Livewire 4, Pest 4, Twilio MMS, Facebook Graph API, OpenAI gpt-image-1 via Laravel AI SDK, S3 storage

**Spec:** `docs/superpowers/specs/2026-03-16-multimedia-bot-flows-design.md`

---

## Chunk 1: Database Migrations & Model Evolution

### Task 1: SMS Bot Flow Migration — Add Keywords, Remove Hardcoded Messages

**Files:**
- Create: `database/migrations/xxxx_evolve_sms_bot_flows_for_step_driven_system.php`
- Modify: `app/Models/SmsBotFlow.php`

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration evolve_sms_bot_flows_for_step_driven_system --table=sms_bot_flows --no-interaction
```

- [ ] **Step 2: Write migration content**

The migration should:
- Add `trigger_keyword` (string, nullable, unique after index)
- Add `completion_action` (string, nullable, default null)
- Drop columns: `greeting_message`, `photo_prompt_message`, `visualization_message`, `portal_link_message`

Use a `DB::getDriverName()` check for SQLite compatibility (SQLite can't drop columns in the same way). For SQLite, skip the drops — the columns just go unused.

- [ ] **Step 3: Update SmsBotFlow model**

In `app/Models/SmsBotFlow.php`:
- Update `$fillable`: remove `greeting_message`, `photo_prompt_message`, `visualization_message`, `portal_link_message`. Add `trigger_keyword`, `completion_action`.
- Add `findByKeyword(string $keyword): ?self` static method — queries `active()` where `trigger_type = 'keyword'` and `trigger_keyword = strtoupper(trim($keyword))`.
- Keep existing `findForTrigger()` for programmatic triggers.

- [ ] **Step 4: Run migration**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 5: Commit**

```bash
git add database/migrations/*evolve_sms_bot_flows* app/Models/SmsBotFlow.php
git commit -m "feat: add keyword triggers and completion action to SmsBotFlow, remove hardcoded message columns"
```

---

### Task 2: SMS Bot Step Migration — New Step Types & Columns

**Files:**
- Create: `database/migrations/xxxx_add_media_and_ai_columns_to_sms_bot_steps.php`
- Modify: `app/Models/SmsBotStep.php`

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration add_media_and_ai_columns_to_sms_bot_steps --table=sms_bot_steps --no-interaction
```

- [ ] **Step 2: Write migration content**

Add columns:
- `media_url` (string, nullable)
- `source_step_id` (unsignedBigInteger, nullable, FK to `sms_bot_steps.id` with `nullOnDelete()`)
- `wait_for_media` (boolean, default false)

- [ ] **Step 3: Update SmsBotStep model**

In `app/Models/SmsBotStep.php`:
- Add new TYPE_ constants: `TYPE_MESSAGE = 'message'`, `TYPE_MEDIA_MESSAGE = 'media_message'`, `TYPE_AI_VISUALIZATION = 'ai_visualization'`, `TYPE_QUICK_REPLY = 'quick_reply'`
- Remove `TYPE_PHOTO_RECEIVED` constant
- Keep existing `TYPE_PHOTO_PROMPT` constant (already exists)
- Update `$fillable` to include `media_url`, `source_step_id`, `wait_for_media`
- Update `casts()` to include `'wait_for_media' => 'boolean'`
- Add `sourceStep(): BelongsTo` relationship
- Update `requiresResponse()` to return true for `TYPE_PHOTO_PROMPT` and `TYPE_AI_VISUALIZATION`
- Update `isQuestion()` to include `TYPE_QUESTION` only

- [ ] **Step 4: Run migration**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 5: Commit**

```bash
git add database/migrations/*add_media_and_ai_columns_to_sms_bot_steps* app/Models/SmsBotStep.php
git commit -m "feat: add media_url, source_step_id, wait_for_media to SmsBotStep with new step types"
```

---

### Task 3: SMS Conversation Migration — Step-Driven State

**Files:**
- Create: `database/migrations/xxxx_evolve_sms_conversations_to_step_driven.php`
- Modify: `app/Models/SmsConversation.php`

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration evolve_sms_conversations_to_step_driven --table=sms_conversations --no-interaction
```

- [ ] **Step 2: Write migration content**

- Add `current_step_id` (unsignedBigInteger, nullable, FK to `sms_bot_steps.id` with `nullOnDelete()`)
- Add `status` (string, default `'in_progress'`)
- Make `project_preview_id` nullable (use `DB::getDriverName()` check — MySQL does `->nullable()->change()`, SQLite can't alter so skip)
- Drop `current_state` column (use driver check — MySQL drops it, SQLite skips)

- [ ] **Step 3: Update SmsConversation model**

In `app/Models/SmsConversation.php`:
- Remove ALL `STATE_*` constants (lines 14-32)
- Update `$fillable`: remove `current_state`, add `current_step_id`, `status`
- Add `currentStep(): BelongsTo` relationship to `SmsBotStep`
- Rewrite `scopeActive()`: `->where('status', 'in_progress')` with expiry check
- Rewrite `isActive()`: `return $this->status === 'in_progress' && (!$this->expires_at || $this->expires_at->isFuture())`
- Rewrite `isHumanTakeover()`: `return $this->status === 'human_takeover'`
- Remove `transitionTo()` method (no longer needed — step advancement handles state)
- Add `isComplete()`: `return $this->status === 'completed'`
- Add `isAbandoned()`: `return $this->status === 'abandoned'`

- [ ] **Step 4: Run migration**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 5: Commit**

```bash
git add database/migrations/*evolve_sms_conversations* app/Models/SmsConversation.php
git commit -m "feat: evolve SmsConversation to step-driven with current_step_id and status"
```

---

### Task 4: ColorVisualization Migration — Link to SmsConversation

**Files:**
- Create: `database/migrations/xxxx_add_sms_conversation_id_to_color_visualizations.php`
- Modify: `app/Models/ColorVisualization.php`

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration add_sms_conversation_id_to_color_visualizations --table=color_visualizations --no-interaction
```

- [ ] **Step 2: Write migration content**

Add `sms_conversation_id` (unsignedBigInteger, nullable, FK to `sms_conversations.id` with `nullOnDelete()`).

- [ ] **Step 3: Update ColorVisualization model**

In `app/Models/ColorVisualization.php`:
- Add `sms_conversation_id` to `$fillable`
- Add `smsConversation(): BelongsTo` relationship

- [ ] **Step 4: Run migration**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 5: Commit**

```bash
git add database/migrations/*add_sms_conversation_id* app/Models/ColorVisualization.php
git commit -m "feat: add sms_conversation_id to ColorVisualization for bot flow callback"
```

---

### Task 5: Messenger Bot Migrations — Keyword Trigger & Media Steps

**Files:**
- Create: `database/migrations/xxxx_add_keyword_trigger_to_messenger_bot_flows.php`
- Create: `database/migrations/xxxx_add_media_to_messenger_bot_steps.php`
- Modify: `app/Models/MessengerBotFlow.php`
- Modify: `app/Models/MessengerBotStep.php`

- [ ] **Step 1: Create both migrations**

```bash
php artisan make:migration add_keyword_trigger_to_messenger_bot_flows --table=messenger_bot_flows --no-interaction
php artisan make:migration add_media_to_messenger_bot_steps --table=messenger_bot_steps --no-interaction
```

- [ ] **Step 2: Write messenger_bot_flows migration**

Add `trigger_keyword` (string, nullable, unique).

- [ ] **Step 3: Write messenger_bot_steps migration**

Add `media_url` (string, nullable).

- [ ] **Step 4: Update MessengerBotFlow model**

- Add `trigger_keyword` to `$fillable`
- Add `findByKeyword(string $keyword): ?self` static method

- [ ] **Step 5: Update MessengerBotStep model**

- Add `TYPE_MEDIA_MESSAGE = 'media_message'` and `TYPE_PHOTO_PROMPT = 'photo_prompt'` constants
- Add `media_url` to `$fillable`

- [ ] **Step 6: Run migrations**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 7: Commit**

```bash
git add database/migrations/*keyword_trigger_to_messenger* database/migrations/*media_to_messenger* app/Models/MessengerBotFlow.php app/Models/MessengerBotStep.php
git commit -m "feat: add keyword triggers to MessengerBotFlow, media step types to MessengerBotStep"
```

---

## Chunk 2: SmsBotFlowService — Core Step Engine

### Task 6: Write SmsBotFlowService — Basic Step Walker (TDD)

**Files:**
- Create: `app/Services/SmsBotFlowService.php`
- Create: `tests/Feature/SmsBotFlowServiceTest.php`

- [ ] **Step 1: Write failing tests for basic flow mechanics**

Create `tests/Feature/SmsBotFlowServiceTest.php` with Pest tests:

1. `it('starts a conversation and sends greeting')` — creates a flow with a greeting step, calls `startConversation()`, asserts conversation is created with `status = 'in_progress'` and `current_step_id` set. Mock `TwilioService::sendSms()`.
2. `it('auto-advances past greeting and message steps')` — flow with greeting → message → question steps. After `startConversation()`, conversation should be on the question step (greeting and message auto-advanced).
3. `it('waits for response on question steps')` — flow with question step. After advancing to it, conversation stays on that step until `processResponse()` is called.
4. `it('stores answers with step key')` — call `processResponse()` with text, verify `answers["step_{id}"]` is stored.
5. `it('completes conversation when no more steps')` — flow with only a greeting step. After starting, status should be `completed`.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/SmsBotFlowServiceTest.php
```

Expected: FAIL (SmsBotFlowService class doesn't exist yet)

- [ ] **Step 3: Implement SmsBotFlowService core**

Create `app/Services/SmsBotFlowService.php` with:
- Constructor: inject `TwilioService`
- `startConversation(string $phone, SmsBotFlow $flow, ?ChatThread $thread = null): SmsConversation`
  - If `$thread` is null, create a new `ChatThread` with `type: support`, `source: 'sms_bot'`, add internal users with `chat.view_website_leads` permission
  - Create `SmsConversation` with `flow_id`, `phone`, `status = 'in_progress'`, `chat_thread_id`
  - Call `advanceToNextStep()`
- `processResponse(SmsConversation $conversation, string $text, array $mediaUrls = []): void`
  - Store answer: `answers["step_{currentStep->id}"] = $text`
  - Update counters: `message_count++`, `photo_count += count($mediaUrls)`
  - Update `last_message_at`
  - Call `advanceToNextStep()`
- `advanceToNextStep(SmsConversation $conversation): void`
  - Load flow with steps
  - Find next step (same branching resolution as Messenger: branch target → `next_step_id` → linear sort_order)
  - If no next step, call `completeConversation()`
  - Otherwise update `current_step_id` and handle step type:
    - `greeting`, `message`: send SMS, auto-advance (recursive call)
    - `question`: send SMS, wait (return)
  - Variable replacement using `{{ var }}` syntax
- `completeConversation(SmsConversation $conversation): void`
  - Update `status = 'completed'`, `completed_at = now()`

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/SmsBotFlowServiceTest.php
```

Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/SmsBotFlowService.php tests/Feature/SmsBotFlowServiceTest.php
git commit -m "feat: SmsBotFlowService core — step walker with greeting, message, question types"
```

---

### Task 7: SmsBotFlowService — Branching, Quick Reply, Variables, Field Mapping

**Files:**
- Modify: `app/Services/SmsBotFlowService.php`
- Modify: `tests/Feature/SmsBotFlowServiceTest.php`

- [ ] **Step 1: Write failing tests for branching and advanced features**

Add to `tests/Feature/SmsBotFlowServiceTest.php`:

1. `it('resolves next step via quick reply branch target')` — flow with quick_reply step that has `next_step_id` on an option. Answer matches option → jumps to branch target.
2. `it('resolves next step via explicit next_step_id')` — step with `next_step_id` set. Should jump to that step instead of linear.
3. `it('falls back to linear sort_order when no branching')` — normal sequential flow.
4. `it('replaces template variables in messages')` — step with `{{ company }}` in message text. Should be replaced with company name.
5. `it('sends numbered options for quick_reply on SMS')` — quick_reply step formats options as "Reply 1 for X, 2 for Y" since SMS has no buttons.
6. `it('handles delay steps')` — delay step auto-advances.
7. `it('handles handoff steps')` — handoff step sets status to `human_takeover`.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/SmsBotFlowServiceTest.php
```

- [ ] **Step 3: Implement branching, quick reply, variables, delay, handoff**

In `app/Services/SmsBotFlowService.php`:
- Add `resolveNextStep()` private method (port from `MessengerBotFlowService::resolveNextStep()` lines 261-294)
- Add `findBranchTarget()` private method (port from `MessengerBotFlowService::findBranchTarget()` lines 299-311)
- Handle `quick_reply` step: format options as numbered list in SMS body
- Handle `delay` step: send message, auto-advance
- Handle `handoff` step: send message, call `triggerHumanTakeover()`
- Add `triggerHumanTakeover()` method: set `status = 'human_takeover'`
- Add `replaceMessageVariables()` method using `{{ var }}` syntax with `preg_replace_callback`
- Add `abandonConversation()` method
- Add `checkTimeouts()` method

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/SmsBotFlowServiceTest.php
```

Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/SmsBotFlowService.php tests/Feature/SmsBotFlowServiceTest.php
git commit -m "feat: SmsBotFlowService branching, quick_reply, variables, delay, handoff"
```

---

### Task 8: SmsBotFlowService — Media & AI Visualization Steps

**Files:**
- Modify: `app/Services/SmsBotFlowService.php`
- Modify: `tests/Feature/SmsBotFlowServiceTest.php`
- Modify: `app/Jobs/GenerateColorVisualizationJob.php`

- [ ] **Step 1: Write failing tests for media and AI steps**

Add to `tests/Feature/SmsBotFlowServiceTest.php`:

1. `it('sends MMS for media_message steps')` — flow with `media_message` step and `media_url`. Mock `TwilioService::sendMms()`. Verify MMS sent and auto-advances.
2. `it('waits for photo on photo_prompt steps')` — flow advances to `photo_prompt` step, waits. Call `processResponse()` with `$mediaUrls` array → downloads photo, stores as `ChatAttachment`, advances.
3. `it('creates project preview lazily on photo_prompt if missing')` — conversation has null `project_preview_id`. When photo is received, creates `ProjectPreview` first.
4. `it('dispatches GenerateColorVisualizationJob on ai_visualization step')` — flow has `ai_visualization` step with `source_step_id`. Previous step has answer stored. Verify job is dispatched with correct prompt and `sms_conversation_id`.
5. `it('sends MMS and advances on visualization complete callback')` — call `onVisualizationComplete()` with result image path. Mock `sendMms()`. Verify MMS sent and flow advances to next step.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/SmsBotFlowServiceTest.php
```

- [ ] **Step 3: Implement media_message, photo_prompt, ai_visualization**

In `app/Services/SmsBotFlowService.php`:
- Handle `media_message` step: call `sendMms()` with `$step->media_url`, auto-advance
- Handle `photo_prompt` step: send SMS with message text, wait for response. In `processResponse()`, detect media URLs, download and store photo (reuse download logic from `SmsEngagementBotService::downloadAndStorePhoto()`), create `ChatAttachment` on thread, create `ProjectPreview` if needed. Store photo path in answers.
- Handle `ai_visualization` step: grab prompt from `source_step_id` answer, grab photo path, create `ColorVisualization` with `sms_conversation_id`, dispatch `GenerateColorVisualizationJob`. Do NOT advance — wait for callback. **Error handling:** if the referenced source step answer is empty/missing (user skipped it or branching bypassed it), send an error message like "Sorry, we need more info to generate your visualization" and advance past the AI step to the next one.
- Add `onVisualizationComplete(SmsConversation $conversation, string $resultImagePath): void`: send MMS with result image URL, store as `ChatAttachment`, advance to next step.

- [ ] **Step 4: Modify GenerateColorVisualizationJob**

In `app/Jobs/GenerateColorVisualizationJob.php`, **inside the `try` block**, immediately after line 93 (`event(new ColorVisualizationCompleted(...))`) and before the `} catch` on line 95, add:

```php
// If this visualization was triggered by an SMS bot flow, notify the flow service
if ($this->visualization->sms_conversation_id) {
    $conversation = SmsConversation::find($this->visualization->sms_conversation_id);
    if ($conversation && $conversation->isActive()) {
        app(SmsBotFlowService::class)->onVisualizationComplete($conversation, $resultPath);
    }
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --compact tests/Feature/SmsBotFlowServiceTest.php
```

Expected: All PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/SmsBotFlowService.php tests/Feature/SmsBotFlowServiceTest.php app/Jobs/GenerateColorVisualizationJob.php
git commit -m "feat: SmsBotFlowService media_message, photo_prompt, ai_visualization steps"
```

---

### Task 9: SmsBotFlowService — Chat Thread Integration

**Files:**
- Modify: `app/Services/SmsBotFlowService.php`
- Modify: `tests/Feature/SmsBotFlowServiceTest.php`

- [ ] **Step 1: Write failing tests for chat integration**

1. `it('creates a chat thread when starting a keyword-triggered conversation')` — `startConversation()` with null thread creates `ChatThread` with type `support`, source `sms_bot`, adds internal participants.
2. `it('stores outbound bot messages as ChatMessages on the thread')` — each step that sends an SMS also creates a `ChatMessage` with `metadata.source = 'sms_bot'`.
3. `it('stores inbound responses as ChatMessages on the thread')` — `processResponse()` creates a guest `ChatMessage` with the user's text.
4. `it('stores media attachments as ChatAttachments on outbound messages')` — `media_message` and `ai_visualization` result MMS create `ChatMessage` + `ChatAttachment`.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/SmsBotFlowServiceTest.php
```

- [ ] **Step 3: Implement chat thread integration**

In `SmsBotFlowService`:
- In `startConversation()`: if no thread, create one using same pattern as `TwilioWebhookController::createSmsSupporThread()`. Store on conversation's `chat_thread_id`.
- After sending each outbound SMS, create a `ChatMessage` on the thread (type `text` or `image`, `user_id = null`, `metadata = ['source' => 'sms_bot']`).
- In `processResponse()`: create a guest `ChatMessage` for the inbound response.
- For media messages and AI results: create `ChatAttachment` records linked to the `ChatMessage`.
- Use `ChatService` to create messages so broadcasting works (or call `broadcast()` directly).

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/SmsBotFlowServiceTest.php
```

Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/SmsBotFlowService.php tests/Feature/SmsBotFlowServiceTest.php
git commit -m "feat: SmsBotFlowService stores all messages as ChatMessages for Comms Hub visibility"
```

---

## Chunk 3: Keyword Routing & Retirement

### Task 10: SMS Keyword Routing in TwilioWebhookController

**Files:**
- Modify: `app/Http/Controllers/TwilioWebhookController.php`
- Create: `tests/Feature/SmsKeywordRoutingTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/SmsKeywordRoutingTest.php`:

1. `it('routes keyword message to matching SmsBotFlow')` — send "COLOR" as SMS body. Active `SmsBotFlow` with `trigger_keyword = 'COLOR'`. Verify `SmsBotFlowService::startConversation()` is called.
2. `it('routes keyword case-insensitively')` — send "color" (lowercase). Should match "COLOR" flow.
3. `it('does not match partial keywords')` — send "COLORFUL". Should NOT match "COLOR" flow.
4. `it('falls back to cold inbound routing when no keyword match')` — send "hello". No matching flow. Should go through existing `routeColdInbound()`.
5. `it('skips keyword routing when active conversation exists')` — active `SmsConversation` for this phone. Message should route to existing conversation, not keyword matching.
6. `it('passes media URLs to bot flow service')` — incoming MMS with body "COLOR" and media URLs. Should pass mediaUrls through.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/SmsKeywordRoutingTest.php
```

- [ ] **Step 3: Modify TwilioWebhookController**

In `app/Http/Controllers/TwilioWebhookController.php`, in the `receive()` method:
- After the existing bot conversation check (line 74-78), before `$session = SmsSession::findActiveByPhone(...)` (line 81):
- Add keyword routing:

```php
// Check for keyword-triggered bot flow
$keywordFlow = SmsBotFlow::findByKeyword($body);
if ($keywordFlow) {
    app(SmsBotFlowService::class)->startConversation($formattedPhone, $keywordFlow);
    return $this->twimlResponse();
}
```

- Also route `$mediaUrls` through to `processResponse()` when an active `SmsConversation` is found (the existing line 76 passes mediaUrls but routes to the old service — change to new service).

- [ ] **Step 4: Update the bot conversation routing (line 74-78)**

Change line 76 from:
```php
app(SmsEngagementBotService::class)->handleInbound($formattedPhone, $body, $mediaUrls);
```
to:
```php
app(SmsBotFlowService::class)->processResponse($botConvo, $body, $mediaUrls);
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --compact tests/Feature/SmsKeywordRoutingTest.php
```

Expected: All PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/TwilioWebhookController.php tests/Feature/SmsKeywordRoutingTest.php
git commit -m "feat: SMS keyword routing in TwilioWebhookController"
```

---

### Task 11: Messenger Keyword Routing

**Files:**
- Modify: `app/Services/FacebookMessengerService.php`
- Modify: `app/Services/MessengerBotFlowService.php`
- Create: `tests/Feature/MessengerKeywordRoutingTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/MessengerKeywordRoutingTest.php`:

1. `it('routes keyword message to matching MessengerBotFlow')` — incoming message "COLOR". Active `MessengerBotFlow` with `trigger_keyword = 'COLOR'`. Verify correct flow is started.
2. `it('falls back to default flow when no keyword match')` — message "hello". Should use default or ad-mapped flow.
3. `it('skips keyword routing for returning customers')` — returning customer (existing completed conversation). Should not trigger keyword flow.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/MessengerKeywordRoutingTest.php
```

- [ ] **Step 3: Modify FacebookMessengerService::handleMessage()**

In `app/Services/FacebookMessengerService.php`, in `handleMessage()`:
- AFTER the active conversation check (line 213 `return`) AND AFTER the returning customer check (line 218 `return`), but BEFORE the "resolve flow from referral" logic (line 221), add keyword check:

```php
// Check for keyword-triggered flow
$keywordFlow = MessengerBotFlow::findByKeyword($text);
if ($keywordFlow) {
    app(MessengerBotFlowService::class)->startConversation($thread, $psid, $keywordFlow);
    return;
}
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/MessengerKeywordRoutingTest.php
```

Expected: All PASS

- [ ] **Step 5: Run existing Messenger tests to verify no regressions**

```bash
php artisan test --compact tests/Feature/Messenger/
```

Expected: All existing tests still PASS

- [ ] **Step 6: Commit**

```bash
git add app/Services/FacebookMessengerService.php tests/Feature/MessengerKeywordRoutingTest.php
git commit -m "feat: Messenger keyword routing — match trigger_keyword before default flow"
```

---

### Task 12: Retire SmsEngagementBotService

**Files:**
- Delete: `app/Services/SmsEngagementBotService.php`
- Modify: `app/Jobs/GeneratePreviewVisualizationJob.php`
- Modify: `database/seeders/SmsBotFlowSeeder.php`

- [ ] **Step 1: Update GeneratePreviewVisualizationJob callback**

In `app/Jobs/GeneratePreviewVisualizationJob.php`, method `checkAllVisualizationsComplete()` (line 112-129):
- Change the import from `SmsEngagementBotService` to `SmsBotFlowService`
- Change the callback from `app(SmsEngagementBotService::class)->onVisualizationsReady($conversation)` to `app(SmsBotFlowService::class)->onVisualizationComplete($conversation, $this->visualization->result_image_path ?? '')`

- [ ] **Step 2: Update SmsBotFlowSeeder variable syntax**

In `database/seeders/SmsBotFlowSeeder.php`:
- Update all message templates from `{variable}` to `{{ variable }}` syntax
- Update step types to use the new constants (replace `photo_received` with appropriate new types)

- [ ] **Step 3: Update InitiateSmsBotJob**

In `app/Jobs/InitiateSmsBotJob.php`:
- Change import from `SmsEngagementBotService` to `SmsBotFlowService` and `SmsBotFlow`
- Replace `$botService = app(SmsEngagementBotService::class)` and `$botService->initiate($phone, $customerId, $leadSourceId)` with:
  - Find the flow: `$flow = SmsBotFlow::findForTrigger('new_lead', $this->leadSourceId)`
  - If no flow found, log and return (same as old behavior)
  - Call `app(SmsBotFlowService::class)->startConversation($this->phone, $flow)`

- [ ] **Step 4: Delete SmsEngagementBotService**

```bash
rm app/Services/SmsEngagementBotService.php
```

- [ ] **Step 5: Handle existing test files**

- Delete `tests/Feature/ProjectPreview/SmsEngagementBotServiceTest.php` — replaced by `tests/Feature/SmsBotFlowServiceTest.php`
- Update `tests/Feature/ProjectPreview/TwilioWebhookBotRoutingTest.php` — change imports and mocks from `SmsEngagementBotService` to `SmsBotFlowService`

- [ ] **Step 6: Search for any remaining references and fix**

```bash
grep -r "SmsEngagementBotService" app/ tests/ --include="*.php" -l
```

Fix any remaining references. Known ones already handled:
- `TwilioWebhookController` — updated in Task 10
- `GeneratePreviewVisualizationJob` — updated in Step 1
- `InitiateSmsBotJob` — updated in Step 3
- Test files — updated in Step 5

- [ ] **Step 7: Run all SMS-related tests**

```bash
php artisan test --compact tests/Feature/SmsBotFlowServiceTest.php tests/Feature/SmsKeywordRoutingTest.php
```

Expected: All PASS

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "refactor: retire SmsEngagementBotService, replace with SmsBotFlowService"
```

---

## Chunk 4: Cross-Channel Media Bridge

### Task 13: Inbound SMS MMS → ChatAttachment (Outside Bot Flows)

**Files:**
- Modify: `app/Http/Controllers/TwilioWebhookController.php`
- Create: `tests/Feature/ChatMediaBridgeTest.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Feature/ChatMediaBridgeTest.php`:

1. `it('stores inbound MMS photos as ChatAttachments on SMS threads')` — simulate Twilio webhook with `NumMedia=1` and `MediaUrl0`. Verify `ChatMessage` of TYPE_IMAGE created with `ChatAttachment` linked.
2. `it('handles MMS with both text and photos')` — body has text AND media. Creates message with both content and attachment.
3. `it('stores multiple MMS photos as separate attachments')` — `NumMedia=2`. Creates one message with 2 attachments.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/ChatMediaBridgeTest.php
```

- [ ] **Step 3: Add media download helper**

In `TwilioWebhookController`, add private method:

```php
private function downloadAndStoreTwilioMedia(array $mediaUrls): array
```

This downloads each Twilio media URL via `Http::get()`, stores to default disk at `chat-attachments/{uuid}.{ext}`, and returns array of attachment data `[['path' => ..., 'filename' => ..., 'original_filename' => ..., 'mime_type' => ..., 'file_size' => ...]]`.

Reuse the download logic pattern from `SmsEngagementBotService::downloadAndStorePhoto()` but store in `chat-attachments/` instead of `previews/`.

- [ ] **Step 4: Wire media into cold inbound routing**

Update `routeColdInbound()`, `routeToExistingSession()`, `routeKnownUser()`, `routeUnknownNumber()`, `routeKnownCustomerWithoutAccount()` to accept `$mediaUrls` parameter. Also update the call sites in `receive()` (lines 84 and 86) to pass `$mediaUrls` as an argument to these methods. When `$mediaUrls` is non-empty:
- Download and store the media
- Create `ChatMessage` with TYPE_IMAGE (or TYPE_FILE for non-images)
- Create `ChatAttachment` records on the message

For `routeToExistingSession()` which calls `ChatService::sendMessage()` / `sendGuestMessage()`, use `ChatService::sendMessageWithAttachments()` pattern or create attachments manually after message creation.

- [ ] **Step 5: Run tests**

```bash
php artisan test --compact tests/Feature/ChatMediaBridgeTest.php
```

Expected: All PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/TwilioWebhookController.php tests/Feature/ChatMediaBridgeTest.php
git commit -m "feat: inbound SMS MMS photos stored as ChatAttachments for Comms Hub visibility"
```

---

### Task 14: Facebook Messenger Inbound Attachments

**Files:**
- Modify: `app/Services/FacebookMessengerService.php`
- Modify: `tests/Feature/ChatMediaBridgeTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/Feature/ChatMediaBridgeTest.php`:

1. `it('stores Facebook Messenger image attachments as ChatAttachments')` — incoming message with `$message['attachments']` containing image type. Verify `ChatMessage` TYPE_IMAGE + `ChatAttachment` created.
2. `it('handles Facebook message with text and attachments')` — message has both `text` and `attachments`. Creates text message AND attachment message.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/ChatMediaBridgeTest.php
```

- [ ] **Step 3: Add handleAttachments() to FacebookMessengerService**

In `app/Services/FacebookMessengerService.php`:

```php
protected function handleAttachments(string $psid, array $attachments, ChatThread $thread): void
```

- Iterate `$attachments` array
- For each attachment with `type === 'image'`, `type === 'video'`, or `type === 'file'`:
  - Download from `$attachment['payload']['url']`
  - Store to default disk at `chat-attachments/{uuid}.{ext}`
  - Create `ChatMessage` (TYPE_IMAGE for images, TYPE_FILE for others) + `ChatAttachment`

- [ ] **Step 4: Call handleAttachments from handleMessage()**

In `handleMessage()`, after storing the text message (line 201), check for attachments:

```php
$attachments = $message['attachments'] ?? [];
if (!empty($attachments)) {
    $this->handleAttachments($psid, $attachments, $thread);
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --compact tests/Feature/ChatMediaBridgeTest.php
```

Expected: All PASS

- [ ] **Step 6: Run existing Messenger tests**

```bash
php artisan test --compact tests/Feature/Messenger/
```

Expected: All still PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/FacebookMessengerService.php tests/Feature/ChatMediaBridgeTest.php
git commit -m "feat: Facebook Messenger inbound attachments stored as ChatAttachments"
```

---

### Task 15: Outbound Media — SMS Thread Reply with Attachments

**Files:**
- Modify: `app/Jobs/SendSmsThreadReplyJob.php`
- Modify: `tests/Feature/ChatMediaBridgeTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/Feature/ChatMediaBridgeTest.php`:

1. `it('sends MMS when internal user replies to SMS thread with image attachment')` — create SMS inbound thread, create ChatMessage with image ChatAttachment from internal user. Dispatch `SendSmsThreadReplyJob`. Mock `TwilioService::sendMms()`. Verify MMS sent with attachment URL.
2. `it('sends text-only SMS for non-image attachments')` — ChatMessage with PDF attachment. Should send regular SMS (not MMS) since MMS only supports images/video.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/ChatMediaBridgeTest.php
```

- [ ] **Step 3: Modify SendSmsThreadReplyJob**

In `app/Jobs/SendSmsThreadReplyJob.php`, in `handle()` method around line 97:

- Before sending, load message attachments: `$this->message->load('attachments')`
- Check for image attachments: `$imageAttachments = $this->message->attachments->filter(fn($a) => $a->isImage())`
- If image attachments exist, call `$twilio->sendMms($phone, $body, $imageAttachments->map(fn($a) => $a->getUrl())->toArray())`
- Otherwise, call `$twilio->sendSms($phone, $body)` (existing behavior)

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/ChatMediaBridgeTest.php
```

Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/SendSmsThreadReplyJob.php tests/Feature/ChatMediaBridgeTest.php
git commit -m "feat: SendSmsThreadReplyJob sends MMS when message has image attachments"
```

---

### Task 16: Outbound Media — Facebook Messenger Image Sending

**Files:**
- Modify: `app/Services/FacebookMessengerService.php`
- Modify: `app/Jobs/SendFacebookMessageJob.php`
- Modify: `app/Services/ChatService.php`
- Modify: `tests/Feature/ChatMediaBridgeTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/Feature/ChatMediaBridgeTest.php`:

1. `it('sends image attachment to Messenger when internal user replies with image')` — create Facebook thread, ChatMessage with image attachment from internal user. Verify `FacebookMessengerService::sendImageMessage()` is called.

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/ChatMediaBridgeTest.php
```

- [ ] **Step 3: Add sendImageMessage() to FacebookMessengerService**

```php
public function sendImageMessage(string $psid, string $imageUrl): array
{
    return $this->sendMessage($psid, [
        'attachment' => [
            'type' => 'image',
            'payload' => [
                'url' => $imageUrl,
                'is_reusable' => true,
            ],
        ],
    ]);
}
```

- [ ] **Step 4: Update SendFacebookMessageJob**

Add optional `public ?string $mediaUrl = null` constructor parameter. In `handle()`, if `$this->mediaUrl` is set, also call `$messenger->sendImageMessage($this->psid, $this->mediaUrl)`.

- [ ] **Step 5: Update ChatService::syncToFacebookMessenger()**

In `app/Services/ChatService.php`, in `syncToFacebookMessenger()` (line 855):
- After dispatching text message, check if `$message` has image attachments
- For each image attachment, dispatch `SendFacebookMessageJob` with `$mediaUrl = $attachment->getUrl()`

- [ ] **Step 6: Run tests**

```bash
php artisan test --compact tests/Feature/ChatMediaBridgeTest.php
```

Expected: All PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/FacebookMessengerService.php app/Jobs/SendFacebookMessageJob.php app/Services/ChatService.php tests/Feature/ChatMediaBridgeTest.php
git commit -m "feat: Facebook Messenger outbound image support from Comms Hub"
```

---

## Chunk 5: Messenger Media Steps & Seeder

### Task 17: MessengerBotFlowService — Media Step Types

**Files:**
- Modify: `app/Services/MessengerBotFlowService.php`
- Modify: `tests/Feature/Messenger/MessengerBotFlowServiceTest.php`

- [ ] **Step 1: Write failing tests**

Add to `tests/Feature/Messenger/MessengerBotFlowServiceTest.php`:

1. `it('sends image for media_message step and auto-advances')` — flow with `media_message` step with `media_url`. Mock `FacebookMessengerService::sendImageMessage()`. Verify image sent and conversation advances.
2. `it('waits for image on photo_prompt step')` — flow advances to `photo_prompt` step. Verify conversation waits. (Messenger attachment handling from Task 14 routes the photo back to `processResponse()`.)

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --compact tests/Feature/Messenger/MessengerBotFlowServiceTest.php
```

- [ ] **Step 3: Add media step handling to MessengerBotFlowService**

In `app/Services/MessengerBotFlowService.php`, in `advanceToNextStep()`, add cases in the switch:

```php
case MessengerBotStep::TYPE_MEDIA_MESSAGE:
    if ($nextStep->media_url) {
        $this->messengerService->sendImageMessage($psid, $nextStep->media_url);
    }
    if ($messageText) {
        $this->messengerService->sendTextMessage($psid, $messageText);
    }
    $this->advanceToNextStep($conversation);
    break;

case MessengerBotStep::TYPE_PHOTO_PROMPT:
    $this->messengerService->sendTextMessage($psid, $messageText);
    // Wait for user to send photo — will be processed via handleAttachments → processResponse
    break;
```

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact tests/Feature/Messenger/MessengerBotFlowServiceTest.php
```

Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/MessengerBotFlowService.php tests/Feature/Messenger/MessengerBotFlowServiceTest.php
git commit -m "feat: MessengerBotFlowService handles media_message and photo_prompt step types"
```

---

### Task 18: COLOR Flow Seeder

**Files:**
- Create: `database/seeders/ColorVisualizationFlowSeeder.php`

- [ ] **Step 1: Create seeder**

```bash
php artisan make:seeder ColorVisualizationFlowSeeder --no-interaction
```

- [ ] **Step 2: Write seeder content**

Create a `SmsBotFlow` with:
- `name: 'Color Visualization'`
- `trigger_type: 'keyword'`
- `trigger_keyword: 'COLOR'`
- `status: 'active'`
- `completion_action: 'create_lead'`
- `timeout_minutes: 60`

Create `SmsBotStep` records:
1. `greeting` → "Hey! Welcome to our Color Visualization tool!"
2. `media_message` → "Check out this quick tutorial on how to get the best results:" with `media_url` placeholder
3. `question` → "What room are you looking to paint?" with `field_mapping: 'project.rooms'`
4. `question` → "Interior, exterior, or both?" with `field_mapping: 'project.scope_type'`
5. `question` → "Now describe your ideal colors! Be specific — walls, trim, cabinets, ceiling. Example: 'Walls in Sherwin-Williams Agreeable Gray, white trim, navy blue cabinets'"
6. `photo_prompt` → "Send a photo of the room and we'll bring your vision to life!" with `wait_for_media: true`
7. `ai_visualization` → `source_step_id` pointing to step 5
8. `message` → "Hope you love it! Ready for a free in-home estimate? {{ booking_link }}"

Use `firstOrCreate` to prevent duplicates on re-run.

- [ ] **Step 3: Run the seeder**

```bash
php artisan db:seed --class=ColorVisualizationFlowSeeder --no-interaction
```

- [ ] **Step 4: Commit**

```bash
git add database/seeders/ColorVisualizationFlowSeeder.php
git commit -m "feat: COLOR visualization flow seeder with 8-step funnel"
```

---

## Chunk 6: Integration Tests & Cleanup

### Task 19: End-to-End Integration Test — Full COLOR Flow

**Files:**
- Create: `tests/Feature/ColorFlowIntegrationTest.php`

- [ ] **Step 1: Write comprehensive integration test**

Test the full COLOR flow end-to-end:

1. `it('completes full COLOR keyword flow: trigger → questions → photo → AI → MMS result')`:
   - Seed the COLOR flow
   - Simulate Twilio webhook with body "COLOR" → verify flow starts, greeting sent
   - Simulate response "Living Room" → verify question answered, next question sent
   - Simulate response "Interior" → verify answered, color prompt sent
   - Simulate response with color description → verify answered, photo prompt sent
   - Simulate response with `MediaUrl0` → verify photo downloaded, stored as `ChatAttachment`, `GenerateColorVisualizationJob` dispatched
   - Call `onVisualizationComplete()` with mock result path → verify MMS sent, completion message sent
   - Verify conversation `status = 'completed'`
   - Verify all messages visible in `ChatThread` with attachments

Mock: `TwilioService` (sendSms, sendMms), `Http` facade (for Twilio media download), `GenerateColorVisualizationJob` (use `Bus::fake()`)

- [ ] **Step 2: Run the integration test**

```bash
php artisan test --compact tests/Feature/ColorFlowIntegrationTest.php
```

Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/ColorFlowIntegrationTest.php
git commit -m "test: end-to-end integration test for COLOR keyword flow"
```

---

### Task 20: Run Full Test Suite & Fix Regressions

- [ ] **Step 1: Run all SMS and Messenger tests**

```bash
php artisan test --compact tests/Feature/SmsBotFlowServiceTest.php tests/Feature/SmsKeywordRoutingTest.php tests/Feature/MessengerKeywordRoutingTest.php tests/Feature/ChatMediaBridgeTest.php tests/Feature/ColorFlowIntegrationTest.php tests/Feature/Messenger/
```

Expected: All PASS

- [ ] **Step 2: Run Pint**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 3: Run broader test suite to check for regressions**

```bash
php artisan test --compact
```

Fix any failures caused by the retirement of `SmsEngagementBotService` or model changes.

- [ ] **Step 4: Commit any fixes**

```bash
git add -A
git commit -m "fix: resolve test regressions from multimedia bot flows implementation"
```

---

## Summary

| Chunk | Tasks | Description |
|-------|-------|-------------|
| 1 | Tasks 1-5 | Database migrations & model evolution |
| 2 | Tasks 6-9 | SmsBotFlowService core engine |
| 3 | Tasks 10-12 | Keyword routing & retirement of hardcoded bot |
| 4 | Tasks 13-16 | Cross-channel media bridge |
| 5 | Tasks 17-18 | Messenger media steps, COLOR flow seeder |
| 6 | Tasks 19-20 | Integration tests & cleanup |

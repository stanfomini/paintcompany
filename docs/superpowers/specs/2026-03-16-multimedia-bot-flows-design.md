# Multimedia Bot Flows & Cross-Channel Media Integration

**Date:** 2026-03-16
**Status:** Draft
**Scope:** SMS bot flow engine, Messenger keyword triggers, cross-channel media support, Comms Hub media integration

---

## Problem Statement

The current SMS bot (`SmsEngagementBotService`) is a hardcoded state machine — states, transitions, and messages are all in PHP code. This makes it impossible to configure new bot flows (like a "text COLOR for a visualization" marketing funnel) without developer intervention.

Meanwhile, the Messenger bot (`MessengerBotFlowService`) is step-driven and configurable, but lacks keyword-based routing and media support.

Neither channel properly bridges media (photos, renderings, videos) into the Communications Hub, so internal users can't see the full conversation with images.

## Goals

1. **Configurable SMS bot flows** — step-driven system modeled after the Messenger bot, with branching, variable replacement, and field mapping
2. **New step types** for media and AI — `message`, `media_message`, `photo_prompt`, `ai_visualization`
3. **Keyword triggers** on both SMS and Messenger flows — route to specific flows based on first message (e.g., "COLOR")
4. **Cross-channel media in Comms Hub** — all bot media (sent/received) stored as `ChatAttachment` and visible in thread view
5. **Comms Hub media replies** — internal users can reply with attachments that get sent as MMS (SMS) or image attachments (Messenger)
6. **Facebook Messenger media support** — receive and send image attachments
7. **Retire hardcoded SMS bot** — delete `SmsEngagementBotService` and its hardcoded state machine

## Non-Goals

- Unified cross-channel bot engine (SMS and Messenger stay as sibling systems)
- Admin UI for building flows (future phase — flows configured via seeder/tinker for now)
- Video/audio attachment support in Messenger (images first)
- Migrating `MessengerBotFlow.greeting_message` to a step (acceptable debt — Messenger keeps its greeting column for now, SMS uses greeting steps)

---

## Design

### 1. Data Model Changes

#### SmsBotFlow

**Add columns:**
- `trigger_keyword` (string, nullable, unique) — keyword that triggers this flow (e.g., "COLOR"), stored UPPERCASE. Null = default flow for `new_lead` trigger or fallback.
- `completion_action` (string, nullable) — what happens on completion: `create_lead`, `create_deal`, `handoff`, `none`. For `trigger_type = 'new_lead'` flows, this should typically be `none` or `handoff` since the lead already exists. For `trigger_type = 'keyword'` flows, `create_lead` creates a new Customer + Deal from collected field mappings.

**Modify `trigger_type`:**
- Add `'keyword'` as a valid trigger type value. Keyword-triggered flows use `trigger_type = 'keyword'`.
- Existing `'new_lead'` trigger type continues to work for programmatic triggers from `InitiateSmsBotJob`.

**Add static method:**
- `SmsBotFlow::findByKeyword(string $keyword): ?self` — finds active flow by keyword match. Separate from existing `findForTrigger()` which handles programmatic triggers.

**Remove columns:**
- `greeting_message` — becomes a `greeting` step
- `photo_prompt_message` — becomes a `photo_prompt` step
- `visualization_message` — becomes an `ai_visualization` step
- `portal_link_message` — becomes a `message` step with `{{ portal_link }}` variable

#### SmsBotStep

**New step types** (in addition to existing `greeting`, `question`, `delay`, `handoff`):
- `message` — send a text message, no response expected, auto-advance
- `media_message` — send MMS with media (image/video URL), auto-advance
- `photo_prompt` — send message asking for photo, wait for image response (replaces the existing `photo_received` type which is retired)
- `ai_visualization` — async: takes prompt from previous step answer + received photo, runs OpenAI, sends result as MMS, then advances

**Retired step types:**
- `photo_received` — replaced by `ai_visualization` which handles the AI processing role. Remove the `TYPE_PHOTO_RECEIVED` constant.

**Add columns:**
- `media_url` (string, nullable) — for `media_message` steps (tutorial video URL, etc.)
- `source_step_id` (integer, nullable, FK to sms_bot_steps) — for `ai_visualization`: which step's answer to use as the AI prompt. MUST reference a step within the same flow. Handler gracefully handles missing answers by sending an error message and skipping the step.
- `wait_for_media` (boolean, default false) — for `photo_prompt`: step won't advance until an image is received

#### SmsConversation

**Add columns:**
- `current_step_id` (integer, nullable, FK to sms_bot_steps) — which step the conversation is on
- `status` (string, default 'in_progress') — `in_progress`, `completed`, `abandoned`, `human_takeover`

**Add relationship:**
- `currentStep(): BelongsTo` — relationship to `SmsBotStep` via `current_step_id`

**Modify:**
- `project_preview_id` — change to **nullable**. Keyword-triggered flows don't have a preview at start. A `ProjectPreview` is created lazily when the first `photo_prompt` step is reached (the service creates one at that point).
- `scopeActive()` — rewrite to `->where('status', 'in_progress')` instead of checking `current_state`
- `isActive()` — rewrite to `return $this->status === 'in_progress'`
- `isHumanTakeover()` — rewrite to `return $this->status === 'human_takeover'`

**Remove:**
- `current_state` column — replaced by `current_step_id` + `status`
- All `STATE_*` constants — no longer needed

**Keep:**
- `phone`, `answers`, `message_count`, `photo_count`, `project_preview_id`, `flow_id`, `chat_thread_id`, `sms_session_id`, `last_message_at`, `expires_at`, `completed_at`

#### ColorVisualization

**Add column:**
- `sms_conversation_id` (integer, nullable, FK to sms_conversations) — links a visualization to the SMS conversation that triggered it. Used by `GenerateColorVisualizationJob` to call back `SmsBotFlowService::onVisualizationComplete()` when rendering finishes.

#### MessengerBotFlow

**Add column:**
- `trigger_keyword` (string, nullable, unique) — keyword that triggers this flow, stored UPPERCASE. Null = default flow for the Facebook page.

#### MessengerBotStep

**Add step types:**
- `media_message` — send image/attachment via Messenger API, auto-advance
- `photo_prompt` — send message, wait for user to send an image attachment

**Add column:**
- `media_url` (string, nullable) — for `media_message` steps

---

### 2. Service Layer

#### New: `SmsBotFlowService`

Replaces `SmsEngagementBotService`. Modeled after `MessengerBotFlowService`.

**Core methods:**
- `startConversation(string $phone, SmsBotFlow $flow, ?ChatThread $thread = null): SmsConversation` — creates conversation, creates/uses chat thread, sends greeting step, advances. **If `$thread` is null**, creates a new `ChatThread` with `type: support`, `source: sms_bot`, and adds internal users with `chat.view_website_leads` permission as participants (same pattern as `TwilioWebhookController::createSmsSupporThread()`).
- `processResponse(SmsConversation $conversation, string $text, array $mediaUrls = []): void` — stores answer (text or photo), advances to next step
- `advanceToNextStep(SmsConversation $conversation): void` — step walker with branching resolution
- `completeConversation(SmsConversation $conversation): void` — triggers lead/deal creation based on `completion_action`
- `abandonConversation(SmsConversation $conversation): void` — marks abandoned
- `triggerHumanTakeover(SmsConversation $conversation): void` — stops bot, notifies team
- `onVisualizationComplete(SmsConversation $conversation, string $resultImagePath): void` — callback when AI finishes, sends MMS, stores as `ChatAttachment`, advances flow
- `checkTimeouts(): int` — check for timed-out conversations

**Step type handling in `advanceToNextStep`:**

| Step Type | Action | Waits for Response? |
|---|---|---|
| `greeting` | Send text via SMS | No — auto-advance |
| `message` | Send text via SMS | No — auto-advance |
| `media_message` | Send MMS with `media_url` | No — auto-advance |
| `question` | Send text via SMS | Yes — wait for text reply |
| `quick_reply` | Send text with numbered options (SMS has no buttons) | Yes — wait for reply |
| `photo_prompt` | Send text asking for photo. Creates `ProjectPreview` lazily if conversation doesn't have one yet. | Yes — wait for MMS image |
| `ai_visualization` | Grab prompt from `source_step_id` answer + most recent photo. Create `ColorVisualization` with `sms_conversation_id` set. Dispatch `GenerateColorVisualizationJob`. | Yes — async wait for AI |
| `delay` | Wait N seconds, then auto-advance | No — auto-advance |
| `handoff` | Send text, trigger human takeover | Terminal |

**Branching resolution** (same as Messenger):
1. Quick reply branch target (answer-based routing via `next_step_id` in option)
2. Explicit `next_step_id` on the step
3. Linear `sort_order` fallback

**Variable replacement** — uses `{{ variable }}` double-brace syntax (consistent with Messenger):
- `{{ first_name }}`, `{{ last_name }}` — from customer/answers
- `{{ booking_link }}` — booking URL
- `{{ portal_link }}` — project preview portal
- `{{ company }}` — company name

**Field mapping:** Each step can map its answer to `customer.first_name`, `customer.email`, `deal.title`, `project.scope_type`, etc.

#### Keyword Routing

**Keyword matching rules:** Case-insensitive, exact match after trimming whitespace. Keywords are stored UPPERCASE in the database. Comparison: `strtoupper(trim($body)) === $flow->trigger_keyword`. Multi-word messages do NOT match — the user must text the exact keyword only.

**`TwilioWebhookController::receive()`:**
```
incoming SMS
  → check active SmsConversation (existing)
  → if no active conversation:
    → check if strtoupper(trim(body)) matches SmsBotFlow::findByKeyword()
    → if match: start that flow via SmsBotFlowService
    → if no match: existing cold inbound routing (known user, unknown number, etc.)
```

**`FacebookMessengerService::handleMessage()`:**
```
incoming message
  → check active MessengerConversation (existing)
  → if no active conversation and not returning customer:
    → check if strtoupper(trim(text)) matches MessengerBotFlow keyword
    → if match: start that flow
    → if no match: use ad referral flow or default flow (existing behavior)
```

#### AI Visualization Integration

The `ai_visualization` step uses the **existing** OpenAI pipeline:
1. Step activates → grabs the customer's prompt text from the `source_step_id` answer
2. Grabs the most recent photo received during the conversation
3. Creates a `ColorVisualization` record with the prompt + photo path + `sms_conversation_id`
4. Dispatches `GenerateColorVisualizationJob` (existing — uses `ColorVisualizerAgent` + `Image::of()` with OpenAI `gpt-image-1`)
5. Job completion triggers `SmsBotFlowService::onVisualizationComplete()`
6. Service sends the result image as MMS, stores as `ChatAttachment`, advances flow

**Change to `GenerateColorVisualizationJob`:** After the existing `event(new ColorVisualizationCompleted(...))`, add a check — if `$this->visualization->sms_conversation_id` is set, load the conversation and call `SmsBotFlowService::onVisualizationComplete()`.

**Change to `GeneratePreviewVisualizationJob`:** Update `checkAllVisualizationsComplete()` to call `SmsBotFlowService::onVisualizationComplete()` instead of `SmsEngagementBotService::onVisualizationsReady()` (for backwards-compatible `new_lead` flows that use the preview photo pipeline).

---

### 3. Communications Hub Media Integration

#### Inbound: External Media → Chat Attachments

**SMS inbound MMS (outside bot flows):**
- `TwilioWebhookController` methods (`routeColdInbound`, `routeToExistingSession`, `routeKnownUser`, etc.) receive `$mediaUrls` from Twilio webhook
- Download each media URL, store on the application's default filesystem disk (`chat-attachments/{uuid}.{ext}`)
- Create `ChatMessage` (TYPE_IMAGE) + `ChatAttachment` instead of text-only messages
- New helper method on controller or service: `downloadAndStoreTwilioMedia(array $mediaUrls): array`

**SMS bot flow media:**
- All media the bot sends/receives during a flow gets stored as `ChatMessage` + `ChatAttachment` on the conversation's `ChatThread`
- This happens inside `SmsBotFlowService` as it processes each step

**Facebook inbound attachments:**
- `FacebookMessengerService::handleMessage()` — check for `$message['attachments']` array
- For each attachment of type `image`, `video`, `file`: download from Facebook CDN URL, store on default disk, create `ChatMessage` (TYPE_IMAGE) + `ChatAttachment`
- New method: `handleAttachments(string $psid, array $attachments, ChatThread $thread): void`

#### Outbound: Comms Hub Replies → External Channels

**SMS threads — reply with attachment:**
- `SendSmsThreadReplyJob` currently sends text only
- Check if the `ChatMessage` has image attachments → call `TwilioService::sendMms()` with attachment URLs
- Non-image files: send text with download link (MMS only supports images/video)

**Messenger threads — reply with attachment:**
- `ChatService::syncToFacebookMessenger()` currently sends text only
- If message has image attachments, also dispatch image sending
- `SendFacebookMessageJob` gets optional `$mediaUrl` parameter
- `FacebookMessengerService::sendImageMessage(string $psid, string $imageUrl): array` — new method using Facebook attachment template API
- Messages with both text and images: send text first, then image (separate API calls)

#### Storage

All downloaded external media stored via the application's default filesystem disk (same as existing chat attachments — S3 in production, local in dev). No `disk` column is needed on `ChatAttachment`; the existing `getUrl()` method using `storage_url()` works correctly since it assumes the default disk.

Path convention: `chat-attachments/{uuid}.{ext}` — same as existing chat upload system.

#### Display

No UI changes needed. `⚡thread-view.blade.php` (lines 720-747) already renders `ChatAttachment` images inline and non-images as download links. Once media is stored as `ChatAttachment`, it automatically shows up.

---

### 4. Retirement of Hardcoded SMS Bot

**Delete:**
- `app/Services/SmsEngagementBotService.php` — entire file
- All `STATE_*` constants from `SmsConversation`
- `TYPE_PHOTO_RECEIVED` constant from `SmsBotStep`
- Hardcoded message columns from `SmsBotFlow` (`greeting_message`, `photo_prompt_message`, `visualization_message`, `portal_link_message`)

**Modify:**
- `TwilioWebhookController::receive()` — route to `SmsBotFlowService` instead of `SmsEngagementBotService`
- `GeneratePreviewVisualizationJob::checkAllVisualizationsComplete()` — call `SmsBotFlowService::onVisualizationComplete()` instead of `SmsEngagementBotService::onVisualizationsReady()`
- `GenerateColorVisualizationJob::handle()` — add callback to `SmsBotFlowService::onVisualizationComplete()` when `sms_conversation_id` is set
- `SmsConversation` — remove hardcoded states, add `current_step_id`, `status`, `currentStep()` relationship; make `project_preview_id` nullable; rewrite scopes
- `SmsBotFlow` — add `trigger_keyword`, `completion_action`, `findByKeyword()` method; remove hardcoded message columns
- `SmsBotStep` — add new step types, remove `photo_received`; add new columns
- Existing `SmsBotFlowSeeder` — update message variable syntax from `{variable}` to `{{ variable }}`

**Keep unchanged:**
- `TwilioService` — `sendSms()` and `sendMms()` work fine
- `ColorVisualizerAgent` — untouched
- `ProcessPreviewPhotoJob` — stays as-is
- `ProjectPreview`, `ProjectPreviewPhoto`, `ProjectPreviewVisualization` models — untouched

---

### 5. Example "COLOR" Flow (Seeder)

```
SmsBotFlow:
  name: "Color Visualization"
  trigger_type: "keyword"
  trigger_keyword: "COLOR"
  status: "active"
  completion_action: "create_lead"
  timeout_minutes: 60

Steps (sort_order):
  1. greeting        → "Hey! Welcome to our Color Visualization tool!"
  2. media_message   → "Check out this quick tutorial on how to get the best results:"
                       media_url: [tutorial video URL]
  3. question        → "What room are you looking to paint?"
                       field_mapping: project.rooms
  4. question        → "Interior, exterior, or both?"
                       field_mapping: project.scope_type
  5. question        → "Now describe your ideal colors! Be specific — walls, trim, cabinets, ceiling. Example: 'Walls in Sherwin-Williams Agreeable Gray, white trim, navy blue cabinets'"
  6. photo_prompt    → "Send a photo of the room and we'll bring your vision to life!"
                       wait_for_media: true
  7. ai_visualization → source_step_id: 5 (uses step 5 answer as AI prompt)
  8. message         → "Hope you love it! Ready for a free in-home estimate? {{ booking_link }}"
```

---

### 6. Testing Strategy

- **Unit tests** for `SmsBotFlowService`: step advancement, branching, keyword routing, variable replacement, field mapping
- **Feature tests** for full COLOR flow: keyword trigger → questions → photo receipt → AI dispatch → visualization complete callback → MMS sent → flow completes
- **Feature tests** for keyword routing in `TwilioWebhookController`: keyword match → correct flow, no match → cold inbound fallback, multi-word message → no match, case insensitivity
- **Feature tests** for Messenger keyword routing: keyword match → correct flow, no match → default/ad flow
- **Feature tests** for media ↔ chat bridge: inbound MMS creates `ChatAttachment`, outbound MMS creates `ChatAttachment`, Facebook attachments create `ChatAttachment`
- **Feature tests** for Comms Hub replies: SMS thread reply with attachment sends MMS, Messenger thread reply with attachment sends image
- **Existing Messenger tests** updated to cover keyword trigger
- **Mock the AI job** in tests — verify job dispatch and completion callback, don't call OpenAI
- **Verify `source_step_id` validation** — test that missing answers are handled gracefully

---

### 7. Files Affected

**New files:**
- `app/Services/SmsBotFlowService.php`
- `database/migrations/xxxx_evolve_sms_bot_for_step_driven_flows.php`
- `database/migrations/xxxx_add_sms_conversation_id_to_color_visualizations.php`
- `database/migrations/xxxx_add_keyword_trigger_to_messenger_bot_flows.php`
- `database/migrations/xxxx_add_media_to_messenger_bot_steps.php`
- `database/seeders/ColorVisualizationFlowSeeder.php`
- `tests/Feature/SmsBotFlowServiceTest.php`
- `tests/Feature/SmsKeywordRoutingTest.php`
- `tests/Feature/MessengerKeywordRoutingTest.php`
- `tests/Feature/ChatMediaBridgeTest.php`

**Modified files:**
- `app/Models/SmsBotFlow.php` — add `trigger_keyword`, `completion_action`, `findByKeyword()`; remove hardcoded message columns
- `app/Models/SmsBotStep.php` — new step type constants, remove `TYPE_PHOTO_RECEIVED`, new columns
- `app/Models/SmsConversation.php` — add `current_step_id`, `status`, `currentStep()` relationship; make `project_preview_id` nullable; remove `STATE_*` constants; rewrite `scopeActive()`, `isActive()`, `isHumanTakeover()`
- `app/Models/ColorVisualization.php` — add `sms_conversation_id` relationship
- `app/Models/MessengerBotFlow.php` — add `trigger_keyword`
- `app/Models/MessengerBotStep.php` — add `media_message`/`photo_prompt` types, `media_url`
- `app/Http/Controllers/TwilioWebhookController.php` — keyword routing, media download for cold inbound
- `app/Services/FacebookMessengerService.php` — keyword routing, `sendImageMessage()`, `handleAttachments()`
- `app/Services/ChatService.php` — `syncToFacebookMessenger()` handles attachments
- `app/Services/MessengerBotFlowService.php` — handle `media_message`/`photo_prompt` step types
- `app/Jobs/SendSmsThreadReplyJob.php` — check for attachments, send MMS
- `app/Jobs/SendFacebookMessageJob.php` — optional `$mediaUrl` parameter
- `app/Jobs/GenerateColorVisualizationJob.php` — add `SmsBotFlowService::onVisualizationComplete()` callback when `sms_conversation_id` is set
- `app/Jobs/GeneratePreviewVisualizationJob.php` — completion callback to new service
- `database/seeders/SmsBotFlowSeeder.php` — update variable syntax from `{var}` to `{{ var }}`

**Deleted files:**
- `app/Services/SmsEngagementBotService.php`

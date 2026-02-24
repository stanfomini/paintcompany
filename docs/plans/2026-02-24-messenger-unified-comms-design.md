# Facebook Messenger Integration + Unified Communications Hub

**Date:** 2026-02-24
**Status:** Approved Design
**Goal:** Run Facebook ads with Click-to-Messenger + website widget, auto-qualify leads via chatbot, create customers/deals in CRM, and manage all Messenger conversations bidirectionally from within the app. Build a unified communications page per customer with tabbed views.

---

## Table of Contents

1. [Overview](#1-overview)
2. [Phase 1: Unified Communications Page](#2-phase-1-unified-communications-page)
3. [Phase 2: Facebook Messenger Integration](#3-phase-2-facebook-messenger-integration)
4. [Phase 3: Chatbot Flow Builder](#4-phase-3-chatbot-flow-builder)
5. [Phase 4: Auto Lead Creation](#5-phase-4-auto-lead-creation)
6. [Phase 5: Website Messenger Widget](#6-phase-5-website-messenger-widget)
7. [Database Schema](#7-database-schema)
8. [API & Services](#8-api--services)
9. [Security & Privacy](#9-security--privacy)
10. [Testing Strategy](#10-testing-strategy)

---

## 1. Overview

### The Flow

```
Facebook Ad ("Send Message" CTA)
    -> User opens Messenger
    -> Sends first message
    -> Facebook webhook hits our server
    -> Chatbot flow begins (configured questions)
    -> Each answer stored + mapped to customer fields
    -> COMPLETE: Customer + Deal created (stage: lead), lands in kanban
    -> INCOMPLETE: Customer + Deal created with partial data, human takes over
    -> Team sees conversation in CRM chat, replies go back to Messenger
    -> Customer drawer on customer page shows all comms
```

### Key Decisions

- **Chatbot flow is configurable** - Admin builds question sequences in settings UI
- **Field mapping is manual** - Admin connects each bot answer to a customer/deal field
- **Emails stay separate** - Unified page has tabs, email is its own tab (not merged into chat feed)
- **Bidirectional sync** - Team replies in CRM appear in customer's Messenger; customer replies appear in CRM
- **Hybrid bot** - Bot runs automatically, team can jump in anytime to take over
- **Partial leads still create records** - Even if bot flow isn't completed, we create Customer + Deal with whatever data we have

---

## 2. Phase 1: Unified Communications Page

### What We're Building

A new page per customer that shows ALL communication in one place, organized by tabs.

### Route

`/customers/{customer}/communications`

### Tab Layout

```
[ View All ] [ Messenger ] [ Deal Chats ] [ Direct ] [ Emails ]
```

**View All tab:**
- Chronological list, most recent first
- Each row: channel icon (chat bubble, FB icon, envelope), preview text, timestamp, sender
- Click a row -> opens that conversation in its native tab
- Pulls from: chat_threads (all types) + emails related to this customer
- This is a read-only aggregated list, not a merged chat feed

**Messenger tab:**
- All Facebook Messenger threads for this customer
- Full chat interface - can read and reply (replies go to Messenger)
- Shows bot messages, customer messages, and team messages
- Indicator for bot-active vs human-takeover status

**Deal Chats tab:**
- Existing deal chat threads (type=deal) for this customer's deals
- Same UI as current deal chat, filtered to this customer

**Direct tab:**
- 1:1 direct messages with this customer
- Same as current direct chat, filtered

**Emails tab:**
- Email threads related to this customer
- Read-only view referencing the existing email system
- Shows: subject, date, preview, sent/received indicator

### Customer Page Drawer

- Slide-out panel on `/customers/{customer}` show page
- Same tabbed layout, compact
- Trigger: button/icon on the customer page (e.g., "Communications" or chat icon)
- Click any conversation to expand or navigate to full communications page

### New Components

| Component | Location | Purpose |
|-----------|----------|---------|
| `⚡communications-hub.blade.php` | `resources/views/components/customers/` | Full-page unified comms |
| `⚡communications-drawer.blade.php` | `resources/views/components/customers/` | Slide-out drawer on customer show |
| `⚡communication-timeline.blade.php` | `resources/views/components/customers/` | "View All" chronological list |

### Changes to Existing

- **ChatThread model** - Add `channel` column (enum: `internal`, `facebook`; default `internal`)
- **ChatThread model** - Add `external_id` column (nullable string, for Facebook thread/conversation ID)
- **ChatThread model** - Add `external_contact_id` column (nullable string, for Facebook PSID)
- **ChatThread model** - Add `customer_id` column (nullable FK to customers, for linking threads to customers independent of deals)
- **Customer show page** - Add communications drawer trigger button

### Why `customer_id` on ChatThread

Currently threads link to customers only through deals (`chat_threads.deal_id -> deals.customer_id`). Messenger threads need to link directly to a customer, especially before a deal exists. Adding `customer_id` also enables the unified comms page to query efficiently.

---

## 3. Phase 2: Facebook Messenger Integration

### Facebook Messenger Platform Setup

**Prerequisites (user handles in Facebook Developer Portal):**
1. Facebook App created in Meta Developer Portal
2. Messenger product added to the app
3. Facebook Page connected to the app
4. Page Access Token generated (long-lived)
5. Webhook subscription configured pointing to our endpoint

**Our app provides:**
- Webhook verification endpoint (GET) - responds to Facebook's hub.challenge
- Webhook receiver endpoint (POST) - receives all message events
- Settings page to paste the Page Access Token and App Secret

### Webhook Endpoints

```
GET  /webhook/facebook/verify    -> FacebookWebhookController@verify
POST /webhook/facebook/receive   -> FacebookWebhookController@receive
```

**Verification** (one-time, when configuring in Facebook portal):
- Facebook sends: `hub.mode=subscribe`, `hub.verify_token=<our_token>`, `hub.challenge=<random>`
- We verify the token matches our stored verify token
- Respond with `hub.challenge` value

**Receive** (every message):
- Facebook sends message events as JSON payload
- We validate `X-Hub-Signature-256` header against App Secret
- Respond 200 immediately (Facebook requires <20s response)
- Queue `ProcessFacebookMessageJob` for async processing

### Message Types We Handle

| Facebook Event | Our Action |
|---------------|------------|
| `messages` (text) | Process through bot flow or route to thread |
| `messages` (attachment: image/file) | Store as ChatAttachment, route to thread |
| `messaging_postbacks` | Handle quick reply button selections |
| `message_reads` | Update read receipts in our system |
| `messaging_optins` | Track opt-in from Send-to-Messenger plugin |

### Sending Messages Back (Bidirectional)

When a team member replies in the CRM chat:

```
Team types message in CRM
    -> ChatService::sendMessage() detects channel=facebook on the thread
    -> Dispatches SendFacebookMessageJob
    -> Job calls Facebook Graph API: POST /{page-id}/messages
    -> Message appears in customer's Messenger
    -> ChatMessage stored locally with metadata.facebook_message_id
```

**Facebook Send API payload:**
```json
{
    "recipient": { "id": "<PSID>" },
    "message": { "text": "Thanks for your interest! We'd love to help." }
}
```

For quick replies (bot questions with button options):
```json
{
    "recipient": { "id": "<PSID>" },
    "message": {
        "text": "Interior or exterior painting? 🏠",
        "quick_replies": [
            { "content_type": "text", "title": "Interior 🎨", "payload": "INTERIOR" },
            { "content_type": "text", "title": "Exterior 🏡", "payload": "EXTERIOR" },
            { "content_type": "text", "title": "Both ✨", "payload": "BOTH" }
        ]
    }
}
```

### Key Service: FacebookMessengerService

```php
class FacebookMessengerService
{
    // Sending
    public function sendTextMessage(string $psid, string $text): array;
    public function sendQuickReply(string $psid, string $text, array $options): array;
    public function sendTypingIndicator(string $psid, bool $on): void;

    // Receiving (called from job)
    public function processIncomingMessage(array $webhookEntry): void;
    public function processPostback(array $webhookEntry): void;

    // Profile
    public function getUserProfile(string $psid): array; // name, profile_pic from Facebook

    // Connection management
    public function verifyWebhook(string $mode, string $token, string $challenge): string;
    public function validateSignature(string $payload, string $signature): bool;
}
```

### Thread Matching Logic

When a message arrives from Facebook:

1. Look up `chat_threads` where `external_contact_id = <PSID>` and `channel = facebook`
2. If found -> route message to existing thread
3. If not found -> create new thread:
   - `type = support` (starts as support-like, can be upgraded)
   - `channel = facebook`
   - `external_contact_id = <PSID>`
   - Fetch user's name from Facebook Graph API
   - Check if PSID matches an existing customer (`messenger_conversations.facebook_psid` -> customer)
   - If match -> link `customer_id`; if not -> leave null (bot will collect info)
4. Start chatbot flow on new threads (if a flow is active)

---

## 4. Phase 3: Chatbot Flow Builder

### Data Model

**MessengerBotFlow** - The overall flow configuration:
- `id`, `name`, `status` (active/inactive/draft), `greeting_message`
- `completion_action` (create_lead), `timeout_minutes` (default 30)
- `business_hours_only` (boolean - if true, only runs during set hours)
- Only ONE flow can be `active` at a time

**MessengerBotStep** - Each question in the sequence:
- `id`, `flow_id`, `sort_order`
- `step_type`: greeting | text_question | quick_reply | delay | handoff
- `message_text` (the message/question, supports emojis)
- `quick_reply_options` (JSON array for button steps: `[{"label": "Interior 🎨", "value": "interior"}, ...]`)
- `field_mapping` (nullable string: which customer/deal field this answer maps to, e.g., `customer.first_name`, `customer.address`, `deal.description`)
- `is_required` (boolean - if true, bot re-asks if no response)
- `delay_seconds` (for delay steps)

**MessengerConversation** - Tracks a person's progress through a flow:
- `id`, `flow_id`, `thread_id` (FK to chat_threads)
- `facebook_psid` (the person's Facebook Page-Scoped ID)
- `current_step_id` (nullable FK to messenger_bot_steps)
- `status`: in_progress | completed | abandoned | human_takeover
- `answers` (JSON object: `{"step_3": "Interior", "step_5": "123 Main St", ...}`)
- `customer_id` (nullable - set once customer is created)
- `deal_id` (nullable - set once deal is created)
- `started_at`, `completed_at`, `last_activity_at`

### Mappable Fields

The field mapping dropdown in the builder shows these options:

**Customer fields:**
- `customer.first_name`
- `customer.last_name`
- `customer.email`
- `customer.phone`
- `customer.address`
- `customer.city`
- `customer.state`
- `customer.zip`
- `customer.notes`

**Deal fields:**
- `deal.title`
- `deal.description`
- `deal.lead_source` (auto-set to "Facebook Messenger" if not mapped)

**No mapping (informational only):**
- Some questions might just be conversational with no field mapping (e.g., "Great choice!")

### Bot Flow Engine

```
Message arrives for thread with active MessengerConversation
    -> MessengerBotFlowService::processResponse($conversation, $messageText)
    -> Store answer for current step
    -> If step has field_mapping -> store in answers JSON
    -> Advance to next step
    -> If next step is greeting/delay -> send message, auto-advance
    -> If next step is question -> send question via FacebookMessengerService
    -> If no more steps -> mark completed, trigger lead creation
    -> If timeout reached -> mark abandoned, trigger partial lead creation
```

### Human Takeover

When an internal user sends a message in a thread that has an active bot conversation:
- Set `messenger_conversations.status = human_takeover`
- Bot stops sending automated questions
- All future messages route normally (human <-> customer)
- The collected answers so far are preserved
- Team member can see what the bot already collected (shown in thread header/sidebar)

### Settings UI

**Route:** `/settings/messenger`

**Sections:**
1. **Connection** - Facebook Page Access Token (encrypted), App Secret, Verify Token, Webhook URL (read-only, for copying)
2. **Bot Flows** - List of flows, create/edit/delete. Only one active at a time.
3. **Flow Editor** - Ordered list of steps. Each step: message text (with emoji picker), type selector, quick reply options (if applicable), field mapping dropdown
4. **Defaults** - Default assigned_to user for new Messenger leads, timeout minutes, business hours

**Components:**

| Component | Location | Purpose |
|-----------|----------|---------|
| `⚡messenger-settings.blade.php` | `resources/views/pages/settings/` | Connection config + flow list |
| `⚡bot-flow-editor.blade.php` | `resources/views/components/settings/` | Step editor for a single flow |

---

## 5. Phase 4: Auto Lead Creation

### When Bot Flow Completes

```php
// MessengerLeadCreationService::createFromConversation($conversation)

1. Extract mapped answers from conversation.answers JSON
2. Check if customer already exists (by email or phone match)
3. If new:
   a. Create Customer record with mapped fields
   b. Set customer.source = 'facebook_messenger'
   c. Set customer.lead_source_id = LeadSource for "Facebook Messenger" (auto-create if needed)
4. If existing:
   a. Update customer with any new fields from the conversation
5. Create Deal:
   a. deal.customer_id = customer.id
   b. deal.stage = 'lead' (lands in kanban)
   c. deal.lead_source = 'Facebook Messenger'
   d. deal.title = "{customer.first_name} {customer.last_name} - Facebook Lead"
   e. deal.assigned_to = settings default or round-robin
6. Link conversation: set customer_id and deal_id
7. Link chat thread: set customer_id on the thread
8. Notify assigned team member (existing notification system)
9. If address was collected -> Property auto-creation follows existing address-to-property logic
```

### When Bot Flow Abandoned/Timeout

Same as above, but:
- Creates Customer + Deal with whatever fields were collected
- Deal.title includes "(Incomplete)" suffix
- Sends notification to assigned user: "Incomplete Messenger lead - needs follow-up"
- The conversation stays open for human takeover

### When Human Takes Over Mid-Flow

- Customer + Deal created immediately with collected data so far
- Team member can see: "Bot collected: Name, Phone. Still need: Address, Email"
- Team member fills in remaining info manually as they chat
- Or they can trigger "re-run remaining bot steps" (stretch goal)

### Duplicate Prevention

- Before creating a customer, check by: email (exact match) or phone (normalized match)
- If duplicate found: link to existing customer, create new deal, notify team about potential duplicate
- Store `facebook_psid` on the messenger_conversation for PSID-level dedup

---

## 6. Phase 5: Website Messenger Widget

### Facebook Chat Plugin

Facebook provides an official Chat Plugin (formerly Customer Chat Plugin) that embeds Messenger on your website.

**Integration:** Single script tag on public-facing pages:
```html
<div id="fb-root"></div>
<div id="fb-customer-chat"
    class="fb-customerchat"
    attribution="biz_inbox"
    page_id="YOUR_PAGE_ID"
    theme_color="#0084ff"
    logged_in_greeting="Hi! Need a painting quote? 🎨"
    logged_out_greeting="Hi! Need a painting quote? 🎨">
</div>
<script>
    // Facebook SDK initialization
</script>
```

**Why this approach:**
- Same webhook receives these messages (they're regular Messenger messages)
- Same bot flow handles them
- No additional backend work needed
- Customer gets one continuous conversation whether they started from ad or website

**Configuration in settings:**
- Toggle: Enable website chat widget (on/off)
- Greeting message for logged-in visitors
- Greeting message for logged-out visitors
- Theme color (match brand)
- Pages to show on (all, specific routes, or exclude certain routes)

**Note:** Facebook has been transitioning their Chat Plugin. If at implementation time the Chat Plugin is deprecated, we can use the Send-to-Messenger button or Messenger URL (`m.me/PAGE_ID`) as alternatives. The backend integration remains the same regardless.

---

## 7. Database Schema

### New Tables

```sql
-- Messenger bot flow configuration
CREATE TABLE messenger_bot_flows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    status ENUM('draft', 'active', 'inactive') DEFAULT 'draft',
    greeting_message TEXT NULLABLE,
    completion_action VARCHAR(50) DEFAULT 'create_lead',
    timeout_minutes INT UNSIGNED DEFAULT 30,
    business_hours_only BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

-- Individual steps in a bot flow
CREATE TABLE messenger_bot_steps (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flow_id BIGINT UNSIGNED NOT NULL,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    step_type ENUM('greeting', 'text_question', 'quick_reply', 'delay', 'handoff') NOT NULL,
    message_text TEXT NOT NULL,
    quick_reply_options JSON NULLABLE,
    field_mapping VARCHAR(100) NULLABLE,
    is_required BOOLEAN DEFAULT TRUE,
    delay_seconds INT UNSIGNED NULLABLE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (flow_id) REFERENCES messenger_bot_flows(id) ON DELETE CASCADE
);

-- Tracks each person's progress through a bot flow
CREATE TABLE messenger_conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    flow_id BIGINT UNSIGNED NOT NULL,
    thread_id BIGINT UNSIGNED NOT NULL,
    facebook_psid VARCHAR(255) NOT NULL,
    current_step_id BIGINT UNSIGNED NULLABLE,
    status ENUM('in_progress', 'completed', 'abandoned', 'human_takeover') DEFAULT 'in_progress',
    answers JSON NULLABLE,
    customer_id BIGINT UNSIGNED NULLABLE,
    deal_id BIGINT UNSIGNED NULLABLE,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    last_activity_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (flow_id) REFERENCES messenger_bot_flows(id) ON DELETE CASCADE,
    FOREIGN KEY (thread_id) REFERENCES chat_threads(id) ON DELETE CASCADE,
    FOREIGN KEY (current_step_id) REFERENCES messenger_bot_steps(id) ON DELETE SET NULL,
    FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
    FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE SET NULL,
    INDEX (facebook_psid),
    INDEX (status)
);

-- Facebook Messenger connection settings (stored in company_settings or separate)
CREATE TABLE messenger_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id VARCHAR(255) NULLABLE,
    page_access_token TEXT NULLABLE,          -- encrypted
    app_secret TEXT NULLABLE,                  -- encrypted
    verify_token VARCHAR(255) NULLABLE,        -- auto-generated UUID
    webhook_url VARCHAR(500) NULLABLE,         -- read-only, auto-generated
    default_assigned_to BIGINT UNSIGNED NULLABLE,
    website_widget_enabled BOOLEAN DEFAULT FALSE,
    widget_greeting_logged_in TEXT NULLABLE,
    widget_greeting_logged_out TEXT NULLABLE,
    widget_theme_color VARCHAR(7) DEFAULT '#0084ff',
    is_connected BOOLEAN DEFAULT FALSE,
    connected_at TIMESTAMP NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    FOREIGN KEY (default_assigned_to) REFERENCES users(id) ON DELETE SET NULL
);
```

### Migrations to Existing Tables

```sql
-- Add channel and external identifiers to chat_threads
ALTER TABLE chat_threads
    ADD COLUMN channel ENUM('internal', 'facebook') DEFAULT 'internal' AFTER type,
    ADD COLUMN customer_id BIGINT UNSIGNED NULLABLE AFTER deal_id,
    ADD COLUMN external_id VARCHAR(255) NULLABLE,
    ADD COLUMN external_contact_id VARCHAR(255) NULLABLE,
    ADD INDEX (channel),
    ADD INDEX (customer_id),
    ADD INDEX (external_contact_id),
    ADD FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL;

-- Update chat_threads type enum to include 'facebook'
-- (or keep using 'support' type with channel='facebook' to distinguish)
```

### Design Decision: Thread Type vs Channel

- `type` stays as-is: `deal`, `direct`, `support` (describes the relationship type)
- `channel` is new: `internal`, `facebook` (describes the communication medium)
- A Facebook Messenger thread starts as `type=support, channel=facebook`
- If a deal is created, the thread can be upgraded to `type=deal, channel=facebook`
- This keeps backward compatibility with all existing chat queries

---

## 8. API & Services

### New Services

| Service | Purpose |
|---------|---------|
| `FacebookMessengerService` | Send/receive messages via Facebook Graph API |
| `MessengerBotFlowService` | Process bot steps, advance conversations, handle timeouts |
| `MessengerLeadCreationService` | Create Customer + Deal from bot conversation data |

### New Controllers

| Controller | Routes |
|------------|--------|
| `FacebookWebhookController` | `GET /webhook/facebook/verify`, `POST /webhook/facebook/receive` |

### New Jobs

| Job | Purpose |
|-----|---------|
| `ProcessFacebookMessageJob` | Async processing of incoming Facebook messages |
| `SendFacebookMessageJob` | Async sending of outgoing messages to Facebook |
| `CheckMessengerTimeoutsJob` | Scheduled job to check for abandoned conversations (runs every 5 min) |

### New Notifications

| Notification | When | To |
|-------------|------|-----|
| `NewMessengerLead` | Bot flow completes or times out | Assigned internal user |
| `MessengerHumanTakeoverNeeded` | Customer is waiting, bot can't handle | Default assigned user |

### Changes to Existing Services

**ChatService:**
- `sendMessage()` - After creating ChatMessage, check if thread `channel=facebook`. If so, dispatch `SendFacebookMessageJob` to sync message to Messenger.
- `sendMessage()` - If thread has active `MessengerConversation` and sender is internal user, trigger human takeover via `MessengerBotFlowService`.

### Events

| Event | Broadcast | Purpose |
|-------|-----------|---------|
| `MessengerMessageReceived` | `chat.thread.{id}` | New message from Facebook (reuses existing MessageSent pattern) |
| `MessengerConversationCompleted` | Internal channel | Bot flow completed, lead created |

---

## 9. Security & Privacy

### Token Storage
- Page Access Token: encrypted in `messenger_settings` (use Laravel's `encrypt()`)
- App Secret: encrypted in `messenger_settings`
- Verify Token: stored as plain UUID (not sensitive, only used for one-time webhook verification)

### Webhook Validation
- Every incoming POST validated against `X-Hub-Signature-256` header using App Secret
- Invalid signatures return 403 and are logged

### Data Privacy
- Facebook PSID is stored (required for messaging)
- Profile data (name, picture) fetched only when needed for display
- No Facebook data shared with third parties
- Customer can request data deletion (existing GDPR flow)
- Bot greeting should include disclosure: "By continuing, you agree to our privacy policy"

### Permissions
- New permissions: `messenger.manage` (configure settings, build flows), `messenger.view` (see conversations), `messenger.respond` (reply to conversations)
- Default: all internal users can view and respond; only admins can manage

---

## 10. Testing Strategy

### Unit Tests
- `MessengerBotFlowServiceTest` - Step advancement, answer storage, field mapping, timeout handling
- `MessengerLeadCreationServiceTest` - Customer/deal creation from answers, duplicate detection, partial data handling
- `FacebookMessengerServiceTest` - Webhook validation, message formatting, API call mocking

### Feature Tests
- `FacebookWebhookTest` - Verify endpoint, receive messages, signature validation, 403 on bad signature
- `MessengerConversationTest` - Full bot flow: start -> answer questions -> complete -> lead created
- `MessengerHumanTakeoverTest` - Bot active, internal user sends message, bot stops, conversation continues
- `MessengerTimeoutTest` - Conversation abandoned after timeout, partial lead created
- `UnifiedCommunicationsTest` - Tab rendering, data filtering, drawer interaction
- `MessengerSettingsTest` - Save/load settings, flow CRUD, step ordering

### Browser Tests
- Bot flow builder drag-and-drop ordering
- Unified comms page tab switching
- Customer drawer open/close
- Real-time message display (via Reverb mock)

---

## Implementation Phases

### Phase 1: Unified Communications Page (Foundation)
**Estimated scope: Medium**
- Migration: Add `channel`, `customer_id`, `external_id`, `external_contact_id` to `chat_threads`
- Build `⚡communications-hub.blade.php` with tabbed layout
- Build `⚡communications-drawer.blade.php` for customer show page
- Build `⚡communication-timeline.blade.php` for View All tab
- Backfill existing threads: set `channel=internal`, link `customer_id` via deals
- Tests for tab filtering, customer linking

### Phase 2: Facebook Webhook + Bidirectional Messaging
**Estimated scope: Large**
- Create `messenger_settings` table and settings page
- Build `FacebookWebhookController` (verify + receive)
- Build `FacebookMessengerService` (send text, quick replies, typing indicators)
- Build `ProcessFacebookMessageJob` and `SendFacebookMessageJob`
- Thread creation/matching logic for incoming messages
- Modify `ChatService::sendMessage()` to dispatch to Facebook when `channel=facebook`
- Webhook signature validation
- Tests for all webhook scenarios

### Phase 3: Chatbot Flow Builder + Field Mapping
**Estimated scope: Large**
- Create `messenger_bot_flows` and `messenger_bot_steps` tables
- Build `⚡messenger-settings.blade.php` (connection + flow list)
- Build `⚡bot-flow-editor.blade.php` (step editor with field mapping dropdowns)
- Build `MessengerBotFlowService` (process responses, advance steps)
- Create `messenger_conversations` table
- Bot flow engine: greeting -> questions -> collect -> complete
- Human takeover detection
- `CheckMessengerTimeoutsJob` scheduled command
- Tests for full flow lifecycle

### Phase 4: Auto Lead Creation from Bot
**Estimated scope: Medium**
- Build `MessengerLeadCreationService`
- Field mapping extraction and customer/deal creation
- Duplicate detection (email/phone)
- Lead source auto-creation ("Facebook Messenger")
- Deal creation in kanban (stage: lead)
- Notifications for new leads and incomplete leads
- Tests for creation, duplicates, partial data

### Phase 5: Website Messenger Widget
**Estimated scope: Small**
- Add widget config to `messenger_settings`
- Blade partial for Facebook Chat Plugin script tag
- Conditional inclusion based on settings toggle
- Settings UI for widget greeting/color/pages
- No additional backend work (same webhook handles these messages)

---

## File Reference

### New Files

```
app/
  Http/Controllers/
    FacebookWebhookController.php
  Services/
    FacebookMessengerService.php
    MessengerBotFlowService.php
    MessengerLeadCreationService.php
  Models/
    MessengerBotFlow.php
    MessengerBotStep.php
    MessengerConversation.php
    MessengerSetting.php
  Jobs/
    ProcessFacebookMessageJob.php
    SendFacebookMessageJob.php
    CheckMessengerTimeoutsJob.php
  Notifications/
    NewMessengerLead.php
    MessengerHumanTakeoverNeeded.php

resources/views/
  pages/settings/
    ⚡messenger-settings.blade.php
  components/
    settings/
      ⚡bot-flow-editor.blade.php
    customers/
      ⚡communications-hub.blade.php
      ⚡communications-drawer.blade.php
      ⚡communication-timeline.blade.php

database/migrations/
    xxxx_add_channel_fields_to_chat_threads_table.php
    xxxx_create_messenger_settings_table.php
    xxxx_create_messenger_bot_flows_table.php
    xxxx_create_messenger_bot_steps_table.php
    xxxx_create_messenger_conversations_table.php

tests/Feature/
  Messenger/
    FacebookWebhookTest.php
    MessengerBotFlowServiceTest.php
    MessengerLeadCreationServiceTest.php
    MessengerConversationTest.php
    MessengerHumanTakeoverTest.php
    MessengerTimeoutTest.php
    MessengerSettingsTest.php
  Customers/
    UnifiedCommunicationsTest.php
```

### Modified Files

```
app/Models/ChatThread.php          -- Add channel, customer_id, external fields
app/Services/ChatService.php       -- Facebook send detection, human takeover trigger
routes/web.php                     -- New routes for webhook, settings, comms page
config/services.php                -- Facebook config keys (optional)
```

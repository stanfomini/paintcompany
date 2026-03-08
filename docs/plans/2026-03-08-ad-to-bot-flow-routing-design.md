# Ad-to-Bot-Flow Routing Design

**Date**: 2026-03-08
**Status**: Approved

## Problem

Currently only one bot flow can be active at a time. All incoming Facebook messages route to that single flow regardless of source. When running multiple ads targeting different audiences (interior vs exterior, seasonal promos, etc.), there's no way to tailor the bot conversation per ad.

## Solution

Route incoming messages to different bot flows based on which Facebook ad the user clicked. The Facebook webhook includes `referral.ad_id` for messages originating from click-to-Messenger ads. We map ad IDs to bot flows in a local table, and use that mapping to select the flow at conversation start.

Messages without ad referral data (organic, website widget, direct page messages) continue using the default active flow — no behavior change.

## Architecture

### Routing Logic

```
Incoming message
  → Has referral.ad_id?
    → Yes → Mapping exists for ad_id?
      → Yes → Use mapped flow
      → No  → Use default active flow
    → No  → Use default active flow (current behavior)
```

The flow is only selected at conversation **start**. Once a conversation is in progress, it stays on its assigned flow regardless of subsequent messages.

### Data Model

**messenger_settings** — add field:
- `ad_account_id` (string, nullable) — Facebook Ad Account ID (e.g. `act_123456789`)

**New table: messenger_ad_flow_mappings**

| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| facebook_ad_id | string | Indexed, unique — the ad ID from Facebook |
| flow_id | foreignId | References messenger_bot_flows, cascade delete |
| facebook_ad_name | string, nullable | Cached for display |
| facebook_campaign_name | string, nullable | Cached for grouping |
| facebook_adset_name | string, nullable | Cached for display |
| facebook_ad_status | string, nullable | ACTIVE, PAUSED, etc. |
| last_synced_at | timestamp, nullable | When ad metadata was last refreshed |
| timestamps | | Standard created_at/updated_at |

### Facebook Marketing API

**Read-only access.** We only need:
- `GET /act_{id}/campaigns?fields=id,name,status` — list campaigns
- `GET /act_{id}/ads?fields=id,name,status,campaign{id,name},adset{id,name}` — list ads with parent info

**Required permissions:**
- `ads_read` — read ad account data (lighter than `ads_management`)

**Token:** Reuse existing Page Access Token if it has `ads_read` scope, or add `ads_read` to the OAuth flow.

### Service Layer

**New: FacebookMarketingService**
- `getAdAccountAds(adAccountId)` — fetches ads from Marketing API, returns structured array
- `syncAds(adAccountId)` — pulls ads and upserts cached metadata in `messenger_ad_flow_mappings`

**Modified: MessengerBotFlowService**
- `startConversation()` — accepts optional `$flowOverride` parameter
- `getFlowForMessage($referralData)` — checks ad_id mapping, returns flow or null

**Modified: FacebookMessengerService**
- `handleMessage()` — extracts `referral` from webhook event, passes to flow service

### UI

New "Ad Routing" section on the messenger settings page (`⚡messenger-settings.blade.php`).

```
[Ad Routing]
Ad Account ID: [act_123456789] [Save]  [Sync Ads]

Campaign: Spring Interior            Status    Bot Flow
├─ Kitchen before/after video        ACTIVE    [Lead Qualification ▼]
├─ 10% off carousel                  ACTIVE    [Interior Quote Flow ▼]
└─ Testimonial reel                  PAUSED    [Default ▼]

Campaign: Exterior Season
├─ Deck refinishing                  ACTIVE    [Exterior Flow ▼]
└─ Full house repaint                ACTIVE    [Default ▼]

Ads without a flow assignment use the default active flow.
```

- "Sync Ads" button pulls fresh data from the Marketing API
- Flow dropdown shows all bot flows + "Default" option
- Changing a flow assignment saves immediately (wire:change)
- Ads grouped by campaign name, sorted by status (active first)

### Webhook Payload

Facebook sends referral data for click-to-Messenger ads:

```json
{
  "sender": { "id": "USER_PSID" },
  "recipient": { "id": "PAGE_ID" },
  "referral": {
    "ref": "optional_custom_ref",
    "source": "ADS",
    "type": "OPEN_THREAD",
    "ad_id": "6045246247433"
  }
}
```

We extract `referral.ad_id` and look up the mapping. The `referral` object may also appear inside `message.referral` or `postback.referral` depending on the event type — handle both.

## What This Does NOT Do

- Create or modify ads in Facebook
- Manage budgets or targeting
- Track ad performance or ROI
- Write ref parameters back to ads
- Affect organic/widget message routing

These are intentionally deferred for future iterations.

## Key Files

| File | Change |
|------|--------|
| `app/Models/MessengerSetting.php` | Add `ad_account_id` field |
| `app/Models/MessengerAdFlowMapping.php` | New model |
| `app/Services/FacebookMarketingService.php` | New service — Marketing API client |
| `app/Services/FacebookMessengerService.php` | Extract referral.ad_id, pass to flow routing |
| `app/Services/MessengerBotFlowService.php` | Accept flow override in startConversation() |
| `resources/views/pages/settings/⚡messenger-settings.blade.php` | Add Ad Routing UI section |
| `database/migrations/..._add_ad_account_id_to_messenger_settings.php` | Migration |
| `database/migrations/..._create_messenger_ad_flow_mappings_table.php` | Migration |
| `tests/Feature/Messenger/AdFlowRoutingTest.php` | New test file |

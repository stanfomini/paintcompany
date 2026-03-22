# Marketing Hub Design Spec

## Context

Painting Made Easy Denver has built a sophisticated lead acquisition pipeline: Facebook ads drive users into Messenger bot flows, which qualify leads and create Customers/Deals automatically. SMS bots do the same via text. The company now needs a centralized Marketing Hub that:

1. Tracks bot conversation performance as the core metric (the Bot Tracker)
2. Houses playbooks for every marketing strategy (geo-targeting, new homeowners, referrals, etc.)
3. Enables live automation for post-job campaigns (referral SMS, review requests, neighborhood targeting)
4. Provides a single dashboard showing the full funnel from ad impression to won job

The Bot Tracker is the default tab because every marketing strategy ultimately funnels leads through the Messenger or SMS bot — it's the central nervous system.

---

## Navigation & Permissions

### Sidebar
- **Location**: Sales group in the internal sidebar (`resources/views/layouts/app/sidebar.blade.php`)
- **Icon**: `megaphone`
- **Label**: "Marketing"
- **Route**: `/marketing` (defaults to Bot Tracker tab)
- **Current detection**: `request()->routeIs('marketing.*')`

### Permissions
| Permission | Roles | Purpose |
|------------|-------|---------|
| `marketing.view` | owner, admin, manager | View dashboard, bot tracker, playbooks |
| `marketing.manage` | owner, admin | Configure automations, activate/pause strategies, edit settings |

Add to `RolesAndPermissionsSeeder.php` in a new `'marketing'` group.

### Routes
New route file: `routes/marketing.php`

```
GET  /marketing                    → marketing.index (redirects to bot-tracker)
GET  /marketing/bot-tracker        → marketing.bot-tracker
GET  /marketing/dashboard          → marketing.dashboard
GET  /marketing/automated          → marketing.automated
GET  /marketing/paid-ads           → marketing.paid-ads
GET  /marketing/new-homeowners     → marketing.new-homeowners
GET  /marketing/partnerships       → marketing.partnerships
```

All wrapped in `middleware(['auth', 'verified', 'auth.internal', 'permission:marketing.view'])`.

Register in `bootstrap/app.php` like existing route files.

---

## Data Model

### `MarketingStrategy` Model

Tracks status and configuration for each strategy.

```
marketing_strategies
├── id (bigint, PK)
├── key (string, unique) — e.g., 'neighborhood_campaigns', 'google_lsa'
├── category (string) — 'automated', 'paid_ads', 'new_homeowners', 'partnerships'
├── name (string)
├── status (string) — 'not_started', 'setting_up', 'active', 'paused'
├── configuration (json, nullable) — strategy-specific settings
├── notes (text, nullable) — user notes/observations
├── activated_at (datetime, nullable)
├── paused_at (datetime, nullable)
├── created_at, updated_at
```

**Seeder** creates all strategies with `not_started` status:

| Key | Category | Name |
|-----|----------|------|
| `neighborhood_campaigns` | automated | Neighborhood Campaigns |
| `referral_program` | automated | Referral Program |
| `review_requests` | automated | Review Requests |
| `sms_reengagement` | automated | SMS Re-engagement |
| `google_lsa` | paid_ads | Google Local Services Ads |
| `geofencing` | paid_ads | Geofencing Paint Stores |
| `retargeting` | paid_ads | Retargeting Pixels |
| `nextdoor` | paid_ads | Nextdoor Advertising |
| `ctv` | paid_ads | Connected TV (CTV) |
| `new_homeowner_triggers` | new_homeowners | New Homeowner Triggers |
| `sherwin_williams` | partnerships | Sherwin-Williams PRO+ |
| `home_inspectors` | partnerships | Home Inspector Referrals |
| `hoa_property_managers` | partnerships | HOA & Property Managers |
| `vehicle_wraps` | partnerships | Vehicle Wraps & QR Codes |
| `yard_signs` | partnerships | Yard Signs & Door Hangers |
| `events` | partnerships | Events & Home Shows |

---

## Page Architecture

### Layout Pattern
Uses the settings layout pattern: left sidebar nav with tabs, right content area.

**Left sidebar**: Vertical nav with tab links (using `<flux:navlist>`)
- Bot Tracker (default, bold/highlighted)
- Dashboard
- Automated Campaigns
- Paid Advertising
- New Homeowners
- Partnerships & Offline

**Right content**: Tab-specific content rendered by each page component.

### Component Structure

```
resources/views/pages/marketing/
├── layout.blade.php              — Shared left-nav + content wrapper
├── ⚡bot-tracker.blade.php       — DEFAULT TAB: Live bot analytics
├── ⚡dashboard.blade.php         — Funnel overview + strategy cards
├── ⚡automated.blade.php         — Automated campaign configs
├── ⚡paid-ads.blade.php          — External platform playbooks
├── ⚡new-homeowners.blade.php    — PropertyRadar pipeline
└── ⚡partnerships.blade.php      — Offline & relationship strategies
```

---

## Tab 1: Bot Tracker (Default)

The nerve center. Shows how every marketing channel converts through the bot pipeline.

### Metrics Bar (Top)
- **Active Conversations**: Count of `MessengerConversation` + `SmsConversation` where status = `in_progress`
- **Completed Today**: Conversations completed in last 24h
- **Leads Created Today**: Conversations where `customer_id` is not null, completed today
- **Conversion Rate**: Completed / Started (rolling 30 days)

### Entry Source Performance (Cards)
Query `MessengerConversation` grouped by `entry_source`:
- **Ad**: Conversations from Facebook ad clicks — count, completion rate, leads created
- **Keyword**: Conversations from trigger keywords — count, completion rate
- **Default**: Organic/direct conversations — count, completion rate

### Ad Campaign Attribution Table
From `MessengerAdFlowMapping` joined with `MessengerConversation`:
- Campaign Name | Ad Name | Conversations | Completed | Leads | Conversion Rate
- Sortable columns
- Links to flow builder for the mapped bot flow

### Bot Flow Performance Table
From `MessengerBotFlow` + `SmsBotFlow` with conversation counts:
- Flow Name | Channel (Messenger/SMS) | Total Convos | Completed | Abandoned | Handoffs | Avg Duration | Conversion Rate
- Click row to expand step-level drop-off analysis

### Step Drop-off Analysis (Expandable per flow)
For each flow, show completion rate at each step:
- Step 1: 100% → Step 2: 82% → Step 3: 71% → ... → Completed: 58%
- Highlights the step with biggest drop-off

### Live Conversation Feed (Collapsible)
Recent conversations (last 24h) as a scrollable list:
- Status badge (in_progress / completed / abandoned / human_takeover)
- Channel icon (Messenger / SMS)
- Entry source badge
- Customer name (from FB profile or phone)
- Current step / time elapsed
- Click to open in chat

### Date Range Filter
- Presets: Today, 7 days, 30 days, 90 days, Custom
- All metrics recalculate based on selected range

### Data Sources
- `MessengerConversation` — Messenger bot conversations
- `SmsConversation` — SMS bot conversations
- `MessengerBotFlow` / `SmsBotFlow` — Flow definitions
- `MessengerBotStep` / `SmsBotStep` — Step definitions for drop-off
- `MessengerAdFlowMapping` — Ad attribution
- No new tables needed. All queries against existing data.

---

## Tab 2: Dashboard

High-level overview of all marketing activity.

### Full Funnel Visualization
Horizontal funnel showing:
Ad Spend → Bot Conversations → Leads → Estimates Sent → Estimates Accepted → Jobs Completed

Each stage shows count and conversion rate to next stage. Data from:
- `MessengerConversation` / `SmsConversation` (conversations)
- `Customer` created from bot (leads)
- `Estimate` with status sent/accepted
- `Job` with status completed

### Strategy Status Grid
Card grid showing all `MarketingStrategy` records:
- Name, status badge (color-coded), category tag
- "Last activated" date
- Quick toggle for active/paused (requires `marketing.manage`)

### Monthly Trends
Line chart (or simple table) showing conversations, leads, and conversion rate over last 6 months. Query `MessengerConversation` grouped by month.

---

## Tab 3: Automated Campaigns

Four strategy sections, each with playbook + configuration.

### Section Pattern (Repeated for each strategy)
Each section is a collapsible card with:
1. **Header**: Strategy name + status badge + activate/pause toggle
2. **Playbook**: What it is, how it works, expected ROI/cost (static content)
3. **Configuration Form** (when expanded, requires `marketing.manage`):
   - Strategy-specific settings stored in `MarketingStrategy.configuration` JSON
4. **Checklist**: Setup steps with completion tracking (stored in configuration JSON)

### 3a. Neighborhood Campaigns
**Playbook**: After completing a painting job, target 100-500 neighboring homes with EDDM postcards + geofenced digital ads. One neighbor conversion pays for 10x the marketing. Expected cost: $325-485 per job site. Expected ROI: 5-15x.

**Configuration**:
- `enabled` (bool) — Master toggle
- `radius_miles` (float, default: 1.0) — Targeting radius from completed job
- `eddm_enabled` (bool) — Auto-queue EDDM postcards
- `digital_ads_enabled` (bool) — Auto-queue geofenced ads
- `postcard_template_notes` (text) — Notes on postcard design/vendor
- `delay_days_after_completion` (int, default: 3) — Days to wait after job completes

**Automation hook**: Listen to Job `completed` stage transition. When triggered and strategy is active, create a `MarketingCampaignLog` entry with the job address and radius for manual or future automated fulfillment.

### 3b. Referral Program
**Playbook**: Send post-job SMS with referral link + reward. Referred leads close at 50-70%. Expected cost: $100-200 per referral paid. Expected ROI: 10-20x.

**Configuration**:
- `enabled` (bool)
- `referrer_reward` (string, default: "$150 cash") — What referrer gets
- `referee_discount` (string, default: "$150 off") — What new customer gets
- `sms_template` (text) — SMS message template with `{referral_link}` variable
- `delay_days_after_completion` (int, default: 7)

**Automation hook**: Listen to Job `completed` stage. Send SMS to customer using Twilio with the configured template. Uses existing `TwilioService`.

### 3c. Review Requests
**Playbook**: Post-job SMS asking for Google/Yelp review mentioning the technology experience. Reviews with service + location keywords feed directly into AI recommendations. Expected cost: $0. Expected ROI: Compounding.

**Configuration**:
- `enabled` (bool)
- `sms_template` (text) — Review request message with `{google_review_link}` and `{yelp_review_link}` variables
- `delay_days_after_completion` (int, default: 3)
- `google_review_url` (url) — Direct Google review link
- `yelp_review_url` (url) — Direct Yelp review link

**Automation hook**: Listen to Job `completed` stage. Send SMS after configured delay.

### 3d. SMS Re-engagement
**Playbook**: Target past customers at 2-3 year intervals for repaints. 98% open rate on SMS. Expected cost: ~$0.01/message. Expected ROI: High (warm leads).

**Configuration**:
- `enabled` (bool)
- `months_since_last_job` (int, default: 24) — Trigger after this many months
- `sms_template` (text) — Re-engagement message
- `exclude_active_deals` (bool, default: true)

**Automation**: Scheduled (daily or weekly check). Query customers whose last completed Job was X months ago, no active deal, has SMS consent. Queue SMS sends.

---

## Tab 4: Paid Advertising

Five external platform playbooks with status tracking.

### Section Pattern
Each section is a collapsible card with:
1. **Header**: Platform name + status badge + link to external platform
2. **Why**: Why this platform matters (with stats from research)
3. **Setup Steps**: Numbered checklist (persisted in `MarketingStrategy.configuration`)
4. **Expected Cost**: Monthly budget range
5. **Expected ROI**: Based on research data
6. **How It Connects to Your Bot**: How leads from this platform enter your Messenger/SMS pipeline
7. **Notes**: Editable text field for tracking account IDs, contacts, etc.

### 4a. Google Local Services Ads
- **Why**: Pay-per-lead ($40-80), appears above all search results, "Google Guaranteed" badge, 31% lead-to-customer conversion
- **Setup**: 8 steps (create account, background check, set budget, configure service areas, etc.)
- **Budget**: $500-2,000/month
- **Bot connection**: Phone calls → can trigger SMS bot flow for follow-up

### 4b. Geofencing Paint Stores
- **Why**: Highest intent signal — people inside Sherwin-Williams, Home Depot, Benjamin Moore
- **Setup**: 6 steps (Meta Business Manager, create audiences, design creative featuring color visualizer, etc.)
- **Budget**: $1,000-3,000/month
- **Bot connection**: Ad click → Messenger bot conversation (via `entry_source: 'ad'`)

### 4c. Retargeting Pixels
- **Why**: Returning visitors 70% more likely to convert. $10-20/day.
- **Setup**: 4 steps (install Meta Pixel, Google tag, create audiences, launch campaigns)
- **Budget**: $300-600/month
- **Bot connection**: Retargeted visitors return to site → lead form or chat widget → bot flow

### 4d. Nextdoor Advertising
- **Why**: Hyper-local, trusted neighbor platform, home services dominate
- **Setup**: 5 steps (claim business page, set up ads, target neighborhoods)
- **Budget**: $500-1,500/month
- **Bot connection**: Link to booking page or landing page → lead form → SMS flow

### 4e. Connected TV (CTV)
- **Why**: ZIP-code targeting, 98% completion rate, Strategus is Denver-local
- **Setup**: 5 steps (contact Strategus, create video, configure targeting)
- **Budget**: $2,000-5,000/month
- **Bot connection**: Brand awareness → when they search later, they find you in LSA/organic

---

## Tab 5: New Homeowners

Dedicated pipeline for targeting new homeowners via public records.

### Playbook
New homeowners paint within 6 months 60% of the time. PropertyRadar ($99/mo) monitors Denver deed transfers weekly and can auto-trigger campaigns.

### Setup Guide
1. Sign up for PropertyRadar ($99/month)
2. Configure Denver metro area monitoring
3. Set filters: residential, single-family, deed transfers
4. Configure postcard automation (PRINTgenie integration)
5. (Future) Configure webhook to auto-create leads in your system

### Configuration
- `enabled` (bool)
- `property_radar_configured` (bool) — Manual checkbox
- `postcard_automation_configured` (bool) — Manual checkbox
- `webhook_url` (readonly) — Display the URL they'd configure in PropertyRadar
- `sms_flow_id` (nullable FK) — Optional SMS bot flow to trigger for new homeowners
- `delay_days_after_purchase` (int, default: 30)
- `notes` (text)

### Stats (Manual Entry for Now)
- Postcards sent this month
- Responses received
- Leads created
- Cost per lead

Stored in `MarketingStrategy.configuration` JSON.

---

## Tab 6: Partnerships & Offline

Six strategy cards for relationship-based and physical marketing.

### Section Pattern
Simpler than automated tabs — each is a collapsible card with:
1. **What**: Description and why it works
2. **How to Start**: 3-5 action steps
3. **Expected ROI**: Based on research
4. **Status**: Toggle + notes field
5. **Checklist**: Setup steps tracked in configuration JSON

### 6a. Sherwin-Williams PRO+
- Join PRO+ program, build store-level relationships, get in-store referrals

### 6b. Home Inspector Referrals
- Partner with inspectors, $50-100 referral fee per job, they see homes that need painting

### 6c. HOA & Property Managers
- 12-18 month sales cycle but massive contract value. Attend HOA meetings, provide proposals

### 6d. Vehicle Wraps & QR Codes
- One-time $2,500-5,000, lasts 3-5 years, 40K-70K impressions/day, QR → booking page

### 6e. Yard Signs & Door Hangers
- $3-5/sign, place after every job (with homeowner permission), most effective combined with digital

### 6f. Events & Home Shows
- Denver Home Show (March), HOA meetings, realtor networking events

---

## Automation Infrastructure

### MarketingCampaignLog Model

Tracks automated campaign executions.

```
marketing_campaign_logs
├── id (bigint, PK)
├── strategy_key (string) — FK-like to MarketingStrategy.key
├── trigger_type (string) — 'job_completed', 'scheduled', 'manual'
├── trigger_id (nullable bigint) — Job ID, Customer ID, etc.
├── trigger_model (nullable string) — 'App\Models\Job', etc.
├── action (string) — 'sms_sent', 'campaign_queued', 'postcard_queued'
├── recipient_phone (nullable string)
├── recipient_address (nullable string)
├── message_body (nullable text)
├── status (string) — 'queued', 'sent', 'delivered', 'failed'
├── metadata (json, nullable)
├── created_at, updated_at
```

### Job Completed Event Listener

New listener: `App\Listeners\TriggerMarketingCampaigns`

Listens to Job stage transition to `completed`. Checks each automated strategy:
1. Is `neighborhood_campaigns` active? → Log campaign with job address
2. Is `referral_program` active? → Schedule SMS after delay
3. Is `review_requests` active? → Schedule SMS after delay

Uses existing `TwilioService` for SMS sending. Delay handled via `dispatch()->delay()`.

### Scheduled Re-engagement Check

New scheduled command (daily): `marketing:check-reengagement`

Queries customers whose last completed job was X months ago, no active deal, has SMS consent. Queues re-engagement SMS for each.

---

## File Summary

### New Files
| File | Purpose |
|------|---------|
| `routes/marketing.php` | Marketing routes |
| `resources/views/pages/marketing/layout.blade.php` | Shared sidebar nav |
| `resources/views/pages/marketing/⚡bot-tracker.blade.php` | Bot Tracker tab |
| `resources/views/pages/marketing/⚡dashboard.blade.php` | Dashboard tab |
| `resources/views/pages/marketing/⚡automated.blade.php` | Automated campaigns tab |
| `resources/views/pages/marketing/⚡paid-ads.blade.php` | Paid advertising tab |
| `resources/views/pages/marketing/⚡new-homeowners.blade.php` | New homeowners tab |
| `resources/views/pages/marketing/⚡partnerships.blade.php` | Partnerships tab |
| `app/Models/MarketingStrategy.php` | Strategy status/config model |
| `app/Models/MarketingCampaignLog.php` | Campaign execution log |
| `database/migrations/..._create_marketing_strategies_table.php` | Strategy table |
| `database/migrations/..._create_marketing_campaign_logs_table.php` | Campaign log table |
| `database/seeders/MarketingStrategySeeder.php` | Seed all 16 strategies |
| `database/factories/MarketingStrategyFactory.php` | Test factory |
| `database/factories/MarketingCampaignLogFactory.php` | Test factory |
| `app/Listeners/TriggerMarketingCampaigns.php` | Job completed listener |
| `app/Console/Commands/MarketingCheckReengagement.php` | Daily re-engagement check |

### Modified Files
| File | Change |
|------|--------|
| `resources/views/layouts/app/sidebar.blade.php` | Add Marketing nav item |
| `database/seeders/RolesAndPermissionsSeeder.php` | Add marketing.view, marketing.manage |
| `bootstrap/app.php` | Register marketing routes |

---

## Verification

1. Navigate to `/marketing` — should redirect to Bot Tracker tab
2. Bot Tracker shows real conversation data from `MessengerConversation` and `SmsConversation`
3. Entry source breakdown shows ad/keyword/default split
4. Dashboard funnel shows conversations → leads → estimates → jobs
5. Strategy cards show correct status and can be toggled (with `marketing.manage`)
6. Automated campaign configs save to `MarketingStrategy.configuration` JSON
7. Playbook content renders correctly with step checklists
8. Permission checks: user without `marketing.view` gets 403
9. User with `marketing.view` but not `marketing.manage` can read but not toggle/configure
10. Tests cover: route access, permission checks, strategy CRUD, bot tracker queries

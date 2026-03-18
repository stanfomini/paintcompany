# Pages & UI

Everything you create is visible in real time on the Template Builder and on the Customer Proposal. Area names become section headers, surface names become line items. There is no hidden data.

## Template Builder Page

### Left Column
- **Project Type Selector** — Interior (blue) / Exterior (green). Filters templates, production rates, wages, margins, cleanup, sundries by scope.
- **Estimate Header** — Deal name, customer name, editable title, valid-until date.
- **Paint Selector** — Single mode (one paint for all) or Category mode (different paint per surface category). When tier pricing is active, shows 3 columns (Good/Better/Best) with separate paint picks per tier.
- **Add Areas** — "+ From Template" (template picker modal with S/M/L sizes) or "+ Custom Area" (opens blank Area Builder).
- **Areas List** — Each area card shows: name with scope badge, L x W x H inline inputs, surface/add-on/hours summary, OTD price, expand toggle (surfaces, add-ons, COGS breakdown), edit/delete buttons.

### Right Sidebar (Sticky)
- **Pricing Options** — Tax rate %, Discount $, Margin discount %, Hourly rate override $/hr, Deposit required %
- **Summary Card** — Subtotal, total hours, tax, discount, total, deposit, balance due (all live-updating)
- **Actions** — Save & Send, Save as Draft, Cancel

### AI Chat Panel
- Sliding panel from right edge; multi-threaded (up to 5 concurrent threads); supports voice input
- Tool calls update the Template Builder in real time

## Area Builder (Detail Modal)

Opens when editing an area card or creating a custom area. Left column = form inputs, right column = live calculator preview.

### Fields

- **Area Name** — Title Case, professional, customer-visible (e.g., "Master Bedroom" not "bedroom 1")
- **Scope Type** — Interior/Exterior; determines wages, margins, rates used
- **Service Category** — Optional internal grouping

### Dimensions

Three measurement modes (pills):

| Mode | Inputs | Use case |
|---|---|---|
| `dimensions` (default) | Width, Height, Length (optional) | Standard rooms |
| `sqft` | wall_sqft, ceiling_sqft directly | Known measurements |
| `lnft` | Linear feet directly | Fence, fascia, trim runs |

**Wall Count (1-4, interior):**
- 4 = fully enclosed room (100% perimeter/wall sqft)
- 3 = open on one side (75%)
- 2 = corner section (50%)
- 1 = single wall/face (25%)

**Auto-calculated from dimensions + wall count:**
- `perimeter_lnft` = `2 * (length + width) * (wall_count / 4)` — used by baseboards, crown, chair rail, fascia
- `wall_sqft` = `perimeter_lnft * height` — used by walls, body/siding
- `ceiling_sqft` = `length * width` — NOT affected by wall_count; 0 when length is null

### Surfaces

Each surface row has:
- **Name** — descriptive, Title Case, customer-visible
- **Surface Category** — determines available production rates AND quantity source (see 03 quantity source table)
  - Interior: `walls`, `ceiling`, `baseboard`, `doors`, `windows`, `trim`, `prep`
  - Exterior: `body`, `eaves`, `fascia`, `trim`, `windows`, `exterior_doors`, `wood_replacement`, `prep`
- **Production Rate** — filtered by surface_category; shows name + speed
- **Quantity Source** — `wall_sqft`, `ceiling_sqft`, `perimeter_lnft`, `door_count`, `window_count`, or `custom`
- **Quantity Override** — for manual counts (doors, windows, custom items)
- **Coats** — 1-5, default 2; affects hours via coat multiplier
- **Custom Pricing Toggle** — replaces rate calc with manual labor hours + material cost inputs

### Fixed-Price Add-ons
Nested template items (closets, half baths, accent walls, repairs) with name, unit price, quantity. Included in area COGS.

### Tier Tabs (Area Builder)
When tier pricing is enabled: [All Tiers] [Good] [Better] [Best]. Each tier stores per-surface snapshots with different coats, rates, paint products, and resulting costs. Without snapshots, tier tabs are empty/broken.

## Two Different "Good/Better/Best" — Don't Confuse Them

There are two separate tier systems that work together:

### 1. Estimate-Level Paint Tiers (Template Builder → Paint Selector)
- **What it is:** Which paint product to use for each tier — e.g., Good=ProMar 200, Better=SuperPaint, Best=Duration
- **Where it lives:** `Estimate.paint_tiers` JSON column
- **What it controls:** Material cost per tier (different paint prices per gallon)
- **Set by:** `set_tier_pricing_ai_tool` or `tier_config` in the batch plan
- **Visible on:** Template Builder left column — 3 paint columns when tiers are active

### 2. Surface-Level Tier Snapshots (Area Builder → Tier Tabs)
- **What it is:** Per-surface per-tier overrides for coats, production rate, labor hours, and material cost
- **Where it lives:** `EstimateAreaSurface.tier_overrides` JSON column
- **What it controls:** How each surface is priced differently per tier (1 coat Good vs 2 coats Better)
- **Set by:** `update_surface_tier_overrides_ai_tool` or auto-applied by `execute_estimate_plan_ai_tool`
- **Visible on:** Area Builder modal — tier tab content

### How They Connect
1. Estimate-level sets the paint product per tier → determines material cost
2. Surface-level snapshots set coats per tier → determines labor hours
3. Together: Good tier = 1 coat budget paint (cheapest). Best tier = 2 coats premium paint (most expensive).
4. **Both are required.** Estimate-level tiers without surface snapshots = tier tabs appear but show $0. Surface snapshots without estimate-level tiers = material costs missing.

### The `execute_estimate_plan_ai_tool` Handles Both
When you pass `tier_config` in the batch plan, PHP automatically:
1. Sets paint products per tier on the estimate (estimate-level)
2. Creates tier snapshots for every surface × every tier (surface-level)
You don't need to think about the two layers — the plan handles both.

## Customer Proposal (Public Page)

Public page the customer receives via email link. Shows: company branding, estimate title, each area as a section header with surface line items, tier columns if enabled, photos, pricing summary (subtotal/tax/discount/total/deposit/balance), digital signature area, and notes. Everything you name appears here verbatim.

## Page Flow

1. User asks you to add a room via AI Chat on Template Builder
2. You search templates, then call create tool — creates EstimateArea + EstimateAreaSurface records
3. Area card appears on Template Builder with name, dimensions, surfaces, price
4. User can click Edit to open Area Builder modal with all data pre-populated
5. User modifies and saves — Template Builder card updates
6. When sent, area names and surface names appear on Customer Proposal

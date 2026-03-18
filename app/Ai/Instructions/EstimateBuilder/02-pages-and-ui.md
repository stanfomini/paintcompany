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

### Tier Tabs
When tier pricing is enabled: [All Tiers] [Good] [Better] [Best]. Each tier stores per-surface snapshots with different coats, rates, paint products, and resulting costs. Without snapshots (via `update_surface_tier_overrides_ai_tool`), tier tabs are empty/broken.

## Customer Proposal (Public Page)

Public page the customer receives via email link. Shows: company branding, estimate title, each area as a section header with surface line items, tier columns if enabled, photos, pricing summary (subtotal/tax/discount/total/deposit/balance), digital signature area, and notes. Everything you name appears here verbatim.

## Page Flow

1. User asks you to add a room via AI Chat on Template Builder
2. You search templates, then call create tool — creates EstimateArea + EstimateAreaSurface records
3. Area card appears on Template Builder with name, dimensions, surfaces, price
4. User can click Edit to open Area Builder modal with all data pre-populated
5. User modifies and saves — Template Builder card updates
6. When sent, area names and surface names appear on Customer Proposal

# Simple Estimate Workflow - Handoff for Deep Dive Session

## Context for Next Session

The user has built a "Simple Estimate Workflow" that aims to be **faster than their spreadsheet**. The basic structure is in place, but now needs a **deep dive to verify accuracy** and identify gaps. The user wants you to:

1. **Analyze the spreadsheet** to ensure we're not missing connections
2. **Ask clarifying questions** about requirements
3. **Research what's actually connected** vs what's hardcoded/estimated
4. **Propose improvements** to truly mimic the spreadsheet calculations

**Reference Spreadsheet**: https://docs.google.com/spreadsheets/d/13jhzT7ulHZVIwUBGfKx2Z-qwaT3IirDrPLZ8AdcuOCw/

---

## What Was Built (Tasks 6-10)

### 1. Room Templates with Size Options
- Templates seeded in `database/seeders/EstimateTemplateSeeder.php`
- Each template has `metadata` JSON with:
  - `sizes`: Small/Medium/Large with sqft values
  - `surface_ratios`: How to calculate surfaces from room sqft
  - `default_size`: Which size to use by default

### 2. Simple Room Picker UI
- Quick Add buttons for common rooms (one-click add)
- Size dropdown that recalculates price instantly
- Located in `resources/views/livewire/estimates/template-builder.blade.php`

### 3. Per-Surface PBP Calculation
- `TemplateBuilder::calculateSurfaceBasedBreakdown()` method
- Calculates each surface using its own production rate
- Formula: labor hours → labor cost → cleanup → sundries → materials → margins

### 4. Expandable Breakdown
- Click chevron to see per-surface details
- Shows surface name, quantity, labor hours

### 5. Spreadsheet Pricing Tests
- 7 tests in `tests/Feature/Livewire/Estimates/SpreadsheetPricingTest.php`
- Verifies PBP formula, coat multipliers, cleanup ratio, sundries, margins

---

## CRITICAL QUESTIONS TO INVESTIGATE

### 1. Are We Actually Using Substrates?

**Current State**: Templates have `substrate_id` field, but the calculation in `calculateSurfaceBasedBreakdown()` does NOT apply substrate multipliers.

**What the spreadsheet does**: Different substrates have different prep/paint multipliers:
- "Previously Painted - Good" = 1.0×
- "Previously Painted - Peeling" = 1.5× (more prep work)
- "New Drywall - Smooth" = different rate entirely
- "Stained Wood" = requires prime coat

**Questions to ask user**:
- Should substrate affect price automatically?
- Should user select substrate per room or per surface?
- Do we need substrate-specific production rates?

### 2. Are Production Rates Correctly Connected?

**Current State**: Surface ratios in templates have `rate_id` pointing to production rates, but:
- Are these the RIGHT production rates from the spreadsheet?
- The spreadsheet has many more specific rates (e.g., "Textured Walls Same Color" = 265 sqft/hr vs "Smooth Walls Change Color" = 120 sqft/hr)
- We only have generic "Wall Painting" = 300 sqft/hr

**What might be missing**:
- Wall texture type (smooth vs textured)
- Color change vs same color (different rates)
- Ceiling types (flat, popcorn, vaulted)
- Prime coat requirements

### 3. Are Company Settings Properly Applied?

**Current State**: `CompanySetting` has scope-specific settings:
- `interior_hourly_wage`, `exterior_hourly_wage`
- `interior_profit_margin`, `exterior_profit_margin`
- `interior_cleanup_ratio`, `exterior_cleanup_ratio`
- etc.

**To verify**:
- Is `getEffectiveHourlyRateForScope()` being called correctly?
- Are all margin calculations using scope-specific values?
- Does payroll burden multiply correctly?

### 4. Should There Be a "Detailed Builder" Option?

**Current Simple Flow**:
```
Click Room → Pick Size → Done
```

**Potential Detailed Flow**:
```
Click Room → Pick Size → Expand Details →
  - Select wall substrate (dropdown)
  - Select ceiling type (dropdown)
  - Toggle surfaces on/off
  - Adjust individual surface quantities
  - Select specific paint product
```

**Question**: Is the simple flow enough, or do salespeople need the detailed option for complex jobs?

### 5. Should We Add Linear Foot Inputs?

**Current State**: Rooms use sqft input, surfaces are calculated from ratios.

**Potential Enhancement**:
- Add slider for room perimeter (linear feet)
- Auto-calculate wall sqft from perimeter × height
- More accurate for odd-shaped rooms

**Example**:
```
Room Perimeter: [====|====] 50 lnft
Ceiling Height: [8 ft ▼]
→ Wall sqft: 400 (50 × 8)
→ Ceiling sqft: ~156 (perimeter² / 4π approximation)
→ Baseboards: 50 lnft
```

### 6. What About Paint Products/Materials?

**Current State**:
- `PaintProduct` model exists with prices and coverage rates
- Templates have `default_paint_product_id`
- BUT: Material cost calculation uses `material_cost_per_unit` from production rate, NOT paint product price

**What spreadsheet does**:
- Paint coverage = 400 sqft/gallon
- Material cost = (sqft / coverage) × paint price per gallon × coats

**To fix**: Should material cost come from `PaintProduct` instead of hardcoded rate?

### 7. Are Nested Area Templates Being Used?

**Existing**: `NestedAreaTemplate` model for fixed-price items (closets, half baths)

**Current State**: Surface ratios have `type: 'fixed'` with `price`, but:
- Is this pulling from `NestedAreaTemplate`?
- Or is it just a hardcoded price in the template metadata?

---

## Files to Review

| File | Purpose | Questions |
|------|---------|-----------|
| `app/Livewire/Estimates/TemplateBuilder.php` | Main component | Is PBP calculation complete? |
| `app/Services/PaintingEstimateCalculatorService.php` | Core PBP engine | Is this being used or bypassed? |
| `app/Models/CompanySetting.php` | Company settings | Are scope-specific methods working? |
| `app/Models/Substrate.php` | Substrate conditions | How should this affect pricing? |
| `app/Models/ProductionRate.php` | Production rates | Do we need more specific rates? |
| `app/Models/PaintProduct.php` | Paint catalog | Should this affect material costs? |
| `database/seeders/EstimateTemplateSeeder.php` | Template definitions | Are surface_ratios correct? |

---

## Recommended Next Steps

### Phase 1: Audit Current Connections
1. Trace calculation from template → price
2. Identify what's actually connected vs hardcoded
3. Compare output to spreadsheet for same inputs

### Phase 2: Gap Analysis
1. List all spreadsheet inputs/calculations
2. Map to our models/services
3. Identify missing pieces

### Phase 3: Decide on Scope
1. Simple flow only?
2. Simple + detailed option?
3. What level of customization do salespeople need?

### Phase 4: Implement Missing Connections
1. Substrate multipliers
2. Paint product material costs
3. More specific production rates
4. Any other gaps found

---

## User's Exact Words

> "Are we actually pulling from the substrates on the production rates tab or did you just make up the numbers on the estimate on the fly. Did we correctly connect everything to production rates and company settings. With this we don't even need a detailed option or do we just give the option to build a custom area and select substrates on the fly like a more in-depth builder. Should we give the ability to have a slider for linear feet and calculate the area for the customer instead of generalized or as an option on top of the square foot selects. There are a lot of things to flush out here to truly mimic what is going on in the sheet."

---

## How to Start Next Session

1. **Use Playwright** to explore the spreadsheet tabs (Inputs, Room tabs, Prices tab)
2. **Compare** spreadsheet calculations to our code output
3. **Ask user** specific questions about workflow preferences
4. **Document** any gaps found
5. **Propose** solutions before implementing

The goal is to make this system **as accurate as the spreadsheet** while being **faster to use**.

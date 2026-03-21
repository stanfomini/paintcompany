# AI Estimate Builder — Refactor Planning Prompt

Use this prompt to enter plan mode for redesigning the AI estimate builder workflow.

---

## Context: What Exists

This is a Laravel 12 / PHP 8.3 painting company CRM with an AI estimating system. The AI builds painting estimates using a chat interface embedded in the estimate builder page.

### Current Architecture

**Agent:** `app/Ai/Agents/EstimateBuilderAgent.php`
- Single conversational agent powered by xAI (Grok)
- `#[MaxSteps(80)]` — 80 tool calls max per request
- `#[Timeout(600)]` — 10 minute timeout
- Loads 9 modular instruction files from `app/Ai/Instructions/EstimateBuilder/`
- Registered tools: 34 tools (read + write mixed together)

**Instruction files** (`app/Ai/Instructions/EstimateBuilder/`):
- `01-role-and-approach.md`
- `02-pages-and-ui.md`
- `03-data-model.md`
- `04-surface-building-guide.md`
- `05-production-rates.md`
- `06-tier-pricing.md`
- `07-tool-reference.md`
- `08-pricing-formula.md`
- `09-rules-and-reminders.md`

**34 AI Tools** in `app/Ai/Tools/Estimate/`:
- Read/discovery: `GetEstimateStateAiTool`, `GetEstimateSummaryAiTool`, `GetPricingConfigAiTool`, `GetProjectContextAiTool`, `ListProductionRatesAiTool`, `ListPaintProductsAiTool`, `ListSubstratesAiTool`, `ListNestedTemplatesAiTool`, `SearchTemplatesAiTool`, `PreviewAreaPricingAiTool`, `ListCostItemsAiTool`
- Area creation: `CreateCustomAreaAiTool`, `CreateAreaFromTemplateAiTool`, `UpdateAreaDimensionsAiTool`, `RemoveAreaAiTool`
- Surface management: `AddSurfaceToAreaAiTool`, `UpdateSurfaceAiTool`, `UpdateSurfaceTierOverridesAiTool`, `RemoveSurfaceAiTool`, `AddAddonToAreaAiTool`
- Pricing/tiers: `SetTierPricingAiTool`, `SetAreaPackageTiersAiTool`, `SetAreaPaintProductAiTool`, `SetEstimatePaintConfigAiTool`
- Creation tools: `CreateProductionRateAiTool`, `CreateTemplateAiTool`, `CreateNestedTemplateAiTool`, `CreateEstimateItemAiTool`
- Cost items: `AddCostItemAiTool`, `RemoveCostItemAiTool`, `ListCostItemsAiTool`
- AI memory: `SaveAiContextAiTool`, `WriteEstimateSummaryAiTool`
- Utilities: `RecalculateEstimateAiTool`, `UpdateEstimateSettingsAiTool`

**Service layer:** `app/Services/EstimateAiService.php` — all business logic, ~1500 lines

**Key models:**
- `EstimateArea` — room/area with L/W/H dimensions
- `EstimateAreaSurface` — surface on an area; has `surface_category` (string), `production_rate_id`, `quantity`, `coats`, `tier_overrides` (JSON)
- `ProductionRate` — has `surface_category` (string), `rate_type` (sqft_hr|lnft_hr|units_hr|hours_per_unit|cost_per_lnft), `production_rate` (float), `scope` (interior|exterior)

**Current surface categories in DB (156 total rates):**
```
interior: walls, ceiling, baseboard, trim, doors, windows, prep
exterior: body, eaves, fascia, trim, exterior_doors, windows, wood_replacement, prep
```

**Quantity auto-calculation** (currently hardcoded in PHP, invisible to agent):
```
walls    → (L+W) * 2 * H  (sqft)
ceiling  → L * W           (sqft)
baseboard → (L+W) * 2     (lnft)
body     → perimeter * H  (sqft, exterior siding)
eaves    → perimeter / 2  (lnft)
fascia   → perimeter      (lnft)
trim/doors/windows/prep → always needs quantity_override (unit counts)
```

---

## The Problem

### Root Cause: Single Agent, No Structure

The agent currently does **discovery + planning + execution all in one unstructured pass**. It improvises as it goes:

1. Gets the request
2. Starts listing production rates on the fly
3. Creates areas
4. Tries to add surfaces
5. Runs out of steps before finishing
6. Reports success anyway (hallucination)

A 3-room request with surfaces + tier overrides for each surface requires 60-80+ tool calls. Even at MaxSteps(80) this is tight, and any inefficiency causes partial results — areas created with no surfaces, wrong categories, duplicate rates created, etc.

### Specific Bugs Found in Testing

1. **Step exhaustion** — agent creates area shells with 0 surfaces, $0 price, reports "done"
2. **No planning phase** — spends 15+ steps on redundant rate lookups it should have done upfront
3. **Category mismatch** — previous agents had invalid `surface_category` values in tool schemas (`siding`, `crown`, `molding` instead of real DB values). Fixed but fragile.
4. **Garbage rate creation** — when a rate isn't found, agent creates new ones with wrong categories and wrong scopes (e.g., `surface_category=prep, scope=exterior` for an interior tile scrub)
5. **Loading indicator stuck** — UI doesn't update when agent completes; user must click away and back
6. **Real-time estimate updates broken** — Livewire broadcasting to update the estimate builder page in real time was attempted but never worked; requires full page refresh to see new areas

### What Works Well

- Category mapping is correct when rates exist (walls/ceiling/baseboard/trim all route correctly)
- Crown molding correctly uses `trim` category
- Garage floor epoxy correctly uses `prep` category
- Tier pricing setup when it gets far enough
- The instruction files are loaded and read correctly
- Paint product lookup works
- Single-room simple requests generally succeed

---

## The Proposed Solution

### Core Concept: Multi-Phase Structured Workflow

Mirror how a human estimator actually works:

```
Request → Understand → Research → Plan → Execute
```

NOT the current:
```
Request → Do everything simultaneously → Hope it fits in 80 steps
```

### Phase 1: Understand

Before calling any tools, the agent must output a structured scope:
- Each room with dimensions
- Each surface needed + what category it maps to
- Edge cases and custom items noted
- Any ambiguities flagged and resolved

### Phase 2: Research

Call `list_production_rates` for every category needed. Record:
- Category
- Best matching rate ID + name + rate value
- If no match exists → flag it, plan to create with correct naming

This is ALL read-only. No modifications yet.

### Phase 3: Plan

Output a complete execution plan before touching anything:

```
Area: Living Room (18×14×9)
├── create_custom_area: name=Living Room, L=18, W=14, H=9
├── walls: rate ID 13 (Smooth Walls change color, 120 sqft/hr), qty=auto
├── ceiling: rate ID 20 (Smooth Ceiling same color, 100 sqft/hr), qty=auto
├── baseboard: rate ID 30 (Baseboards change color, 30 lnft/hr), qty=auto
├── trim/crown: rate ID 129 (Crown Molding, 20 lnft/hr), qty_override=64 lnft
├── doors: rate ID 35 (Doors 1 side, 1.5 hrs/door), qty_override=2
└── windows: rate ID 139 (Window Trim & Sill, 2 units/hr), qty_override=3
```

This takes ZERO write tool calls. It's pure thinking.

### Phase 4: Execute

Execute the plan exactly as written. No additional lookups. No improvising. Every call is predetermined by the plan.

---

## Option A: Single Agent with Enforced Phases (Quick Win)

Rewrite the instruction files to enforce the phased workflow. Add a mandatory preamble to `01-role-and-approach.md`:

```
MANDATORY WORKFLOW — You must follow these phases in exact order.
Do not call any write/modification tool before completing Phase 3.

PHASE 1 — UNDERSTAND (no tool calls)
PHASE 2 — RESEARCH (read-only tools only)
PHASE 3 — PLAN (write your full plan, no tool calls)
PHASE 4 — EXECUTE (follow the plan exactly)
```

**Pros:** No code changes needed, quick to implement, immediate improvement
**Cons:** Still single agent, still has step budget pressure, still can hallucinate

---

## Option B: Two-Agent Pipeline (Proper Solution)

Split into two separate agents with distinct responsibilities:

### Planner Agent (read-only, cheap/fast model)

**Input:** Raw user request + current estimate state
**Tools:** ONLY read tools — `GetEstimateStateAiTool`, `ListProductionRatesAiTool`, `ListPaintProductsAiTool`, `GetProjectContextAiTool`, `SearchTemplatesAiTool`
**Output:** Structured JSON execution plan
**MaxSteps:** 20 (only needs to list rates and think)
**Cost:** Very low — just reading

```json
{
  "areas": [
    {
      "name": "Living Room",
      "dimensions": {"L": 18, "W": 14, "H": 9},
      "scope": "interior",
      "surfaces": [
        {"category": "walls", "rate_id": 13, "qty": "auto", "coats": 2},
        {"category": "trim", "rate_id": 129, "qty_override": 64, "coats": 2},
        {"category": "doors", "rate_id": 35, "qty_override": 2, "coats": 2}
      ],
      "paint_product_id": 4
    }
  ],
  "new_rates_to_create": [
    {"name": "Tile Scrub Prep", "category": "prep", "rate_type": "hours_per_unit", "rate": 2.0, "scope": "interior"}
  ],
  "notes": "Wainscoting uses walls category with qty_override=48 sqft"
}
```

### Executor Agent (write-only, follows plan exactly)

**Input:** The structured JSON plan from Planner
**Tools:** ALL write tools — no discovery tools needed
**Output:** Execution results
**MaxSteps:** N × surfaces (predictable)
**Behavior:** Execute plan items in order, report any failures with specific error

### Architecture Change

In `EstimateBuilderAgent` (or a new controller/service):
1. Run Planner agent first (fast, cheap)
2. Optionally show plan to user for confirmation ("Here's what I'll build — proceed?")
3. Run Executor agent with plan as input

This gives users a preview before any DB writes, makes failures recoverable, and keeps each agent's budget tight and focused.

---

## Option C: Show Plan to User First (Best UX)

Extend Option B with a confirmation step:

1. Planner runs → outputs human-readable plan
2. UI shows plan: "Here's what I'll add: Living Room (walls, ceiling, crown, 2 doors, 3 windows), Bathroom (walls, ceiling, door, wainscoting, tile prep). Total estimated: ~$2,400. Proceed?"
3. User confirms → Executor runs
4. Real failures are reported per-area, not as a silent partial mess

---

## Surface Category Registry (Needed Regardless)

The quantity auto-calculation rules are currently buried in PHP and invisible to the agent. They need to be in the instructions AND potentially in the DB.

**Instruction file addition** (new section in `05-production-rates.md` or new file `05b-category-registry.md`):

```markdown
| Category       | Quantity Formula    | Unit  | Scope    | Override Required | Notes |
|----------------|---------------------|-------|----------|-------------------|-------|
| walls          | (L+W)*2*H           | sqft  | interior | No (auto)         | Wainscoting: qty_override |
| ceiling        | L*W                 | sqft  | both     | No (auto)         | |
| baseboard      | (L+W)*2             | lnft  | interior | No (auto)         | |
| trim           | —                   | lnft  | interior | YES               | Crown, chair rail, casing, cabinets |
| doors          | count               | units | interior | YES               | |
| windows        | count               | units | both     | YES               | |
| prep           | —                   | units/sqft | both | YES            | Always override |
| body           | approx L*H          | sqft  | exterior | Recommended       | Siding, stucco |
| eaves          | perimeter/2         | lnft  | exterior | Recommended       | |
| fascia         | perimeter           | lnft  | exterior | Recommended       | |
| exterior_doors | count               | units | exterior | YES               | |
| wood_replacement | —                 | lnft  | exterior | YES               | |
| [custom]       | —                   | any   | any      | YES (always)      | Use snake_case. e.g. epoxy_floor, tile_scrub |
```

**Custom category rules:**
- Name in `snake_case`, descriptive: `epoxy_floor`, `tile_scrub`, `stair_railing`
- Always pass `quantity_override` — no auto-calculation possible
- The `surface_category` on the rate MUST exactly match the `surface_category` on the surface
- Never use generic names like `other`, `custom`, `misc`

**Code change needed:** Remove the hardcoded category validation in `EstimateAiService::createProductionRate()` (currently restricts to constants array). Allow any string but enforce naming convention via instructions.

---

## UI Bug: Loading Indicator + Real-Time Updates

Two broken things in the frontend that need separate fixes:

1. **Loading indicator stuck** — after agent completes (success or step-limit), the "Building estimate..." spinner stays until user clicks away and back to the thread. The completion event isn't reaching the UI reliably.

2. **Real-time estimate page updates** — the template builder page (`⚡template-builder.blade.php`) was supposed to receive Livewire broadcast events when the AI adds areas/surfaces and update in real time. This was attempted and broke. Currently requires a full page refresh to see new areas.

These are independent of the agent workflow refactor but should be on the list.

---

## Files to Touch

**Agent:**
- `app/Ai/Agents/EstimateBuilderAgent.php` — may need to split into PlannerAgent + ExecutorAgent

**Instructions (primary work):**
- `app/Ai/Instructions/EstimateBuilder/01-role-and-approach.md` — add mandatory phase workflow
- `app/Ai/Instructions/EstimateBuilder/05-production-rates.md` — add full category registry table with formulas
- New file: `app/Ai/Instructions/EstimateBuilder/00-workflow.md` — phase enforcement rules

**Service:**
- `app/Services/EstimateAiService.php` — remove hardcoded category validation in `createProductionRate()`

**Potential new files:**
- `app/Ai/Agents/EstimatePlannerAgent.php` — read-only planner
- `app/Ai/Agents/EstimateExecutorAgent.php` — write-only executor
- `app/Services/EstimateAiPipelineService.php` — orchestrates planner → executor flow

**Frontend (separate):**
- `resources/views/components/estimates/⚡template-builder.blade.php` — fix loading indicator + real-time updates

---

## Priority Order

1. **Instruction rewrite** — phased workflow + category registry (biggest impact, no code changes)
2. **Remove hardcoded category validation** — allow freeform categories
3. **Two-agent split** — Planner + Executor (architectural change)
4. **User confirmation step** — show plan before execution
5. **UI bugs** — loading indicator, real-time updates

---

## Success Criteria

- A 3-room request with complex surfaces succeeds with all areas populated and priced correctly
- No hallucinated success messages — if a surface fails, the agent reports it clearly
- Custom/edge-case surfaces (epoxy floors, wainscoting, tile scrub) get correct categories and quantities
- Production rate lookups happen before execution, not during
- Step usage is predictable: ~10-15 for planning, N for execution where N = number of surfaces

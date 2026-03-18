# AI Instruction Architecture Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure the AI estimate builder's monolithic system prompt into 9 modular markdown instruction files, register all 34 tools, and refactor the agent to compose instructions from files + dynamic context.

**Architecture:** Static instruction content moves from a PHP heredoc into numbered `.md` files in `app/Ai/Instructions/EstimateBuilder/`. The agent's `instructions()` method loads these files in order and appends dynamic context (company settings, estimate state). All existing knowledge is preserved; new content fills gaps (page descriptions, surface building guide, naming conventions, quantity sources).

**Tech Stack:** PHP 8.3, Laravel 12, Laravel AI package, xAI/Grok gateway

**Spec:** `docs/superpowers/specs/2026-03-18-ai-instruction-architecture-design.md`

---

## File Structure

### New Files (9 instruction files)
- `app/Ai/Instructions/EstimateBuilder/01-role-and-approach.md` — AI identity, personality, work style
- `app/Ai/Instructions/EstimateBuilder/02-pages-and-ui.md` — Template builder + area builder page descriptions
- `app/Ai/Instructions/EstimateBuilder/03-data-model.md` — Estimate → Area → Surface → Rate hierarchy
- `app/Ai/Instructions/EstimateBuilder/04-surface-building-guide.md` — Category/rate/quantity decision framework
- `app/Ai/Instructions/EstimateBuilder/05-production-rates.md` — Rate types, reference tables, creation guide
- `app/Ai/Instructions/EstimateBuilder/06-tier-pricing.md` — Good/Better/Best workflow
- `app/Ai/Instructions/EstimateBuilder/07-tool-reference.md` — All 34 tools with when/why/params
- `app/Ai/Instructions/EstimateBuilder/08-pricing-formula.md` — PBP formula with worked example
- `app/Ai/Instructions/EstimateBuilder/09-rules-and-reminders.md` — Mandatory behaviors, user request interpretation

### Modified Files
- `app/Ai/Agents/EstimateBuilderAgent.php` — Replace heredoc with file loader + buildDynamicContext(), register all 34 tools, restore MaxSteps(40) and Timeout(420)

---

## Task 1: Create instruction directory and file 01-role-and-approach.md

**Files:**
- Create: `app/Ai/Instructions/EstimateBuilder/01-role-and-approach.md`

- [ ] **Step 1: Create directory**

```bash
mkdir -p app/Ai/Instructions/EstimateBuilder
```

- [ ] **Step 2: Write 01-role-and-approach.md**

Write the file with content covering: AI identity as expert painting estimator, narrate-decisions approach, research-first approach, flag-unusual-inputs approach, remember-context approach, professional-and-concise response style, the lookup-or-create pattern.

Source content from spec section `01-role-and-approach.md`. This preserves the "Personality & Approach" and "Decision Flow" from the current agent instructions.

- [ ] **Step 3: Commit**

```bash
git add app/Ai/Instructions/EstimateBuilder/01-role-and-approach.md
git commit -m "feat(ai): add 01-role-and-approach instruction file"
```

---

## Task 2: Write 02-pages-and-ui.md (NEW — biggest gap)

**Files:**
- Create: `app/Ai/Instructions/EstimateBuilder/02-pages-and-ui.md`

- [ ] **Step 1: Write 02-pages-and-ui.md**

This is entirely new content. Write with content covering:

**Template Builder Page:** Layout (left column areas list, right sidebar pricing), area cards (name, scope badge, dimensions, surfaces, hours, price, expand/edit/delete), paint selector (single/category modes), add areas section (from template / custom), pricing sidebar (tax, discount, margin, hourly override, deposit), summary card, AI chat panel (multi-threaded, sliding right panel).

**Area Builder Page (modal):** Basic info (area name, scope, service category), dimensions section (3 measurement modes: dimensions/sqft/lnft), wall count selector (1-4 pills with explanations: 4=full room, 3=open room, 2=corner, 1=single wall), length-is-optional for single-face exterior, surfaces section (each surface: name, category, production rate dropdown, quantity source dropdown, quantity override, coats, custom pricing toggle), fixed-price add-ons, live calculator sidebar, tier tabs.

**Customer Proposal Page:** Area names as section headers, surface names within areas, tier options, totals, digital signature.

**How the Two Pages Connect:** 6-step flow from user request → tool calls → area card → area builder → database → proposal.

Source from spec section `02-pages-and-ui.md`.

- [ ] **Step 2: Commit**

```bash
git add app/Ai/Instructions/EstimateBuilder/02-pages-and-ui.md
git commit -m "feat(ai): add 02-pages-and-ui instruction file"
```

---

## Task 3: Write 03-data-model.md

**Files:**
- Create: `app/Ai/Instructions/EstimateBuilder/03-data-model.md`

- [ ] **Step 1: Write 03-data-model.md**

Content covering the complete data hierarchy:

- **Estimate:** title, project_type, paint_tiers, status, totals, ai_context, ai_summary
- **EstimateArea:** name, scope_type, length (optional), width, height, wall_count (1-4), perimeter_lnft formula (`2 × (L+W) × (wall_count/4)`), wall_sqft formula (`perimeter_lnft × height`), ceiling_sqft formula (`L × W`), substrate_id, measurement_type
- **EstimateAreaSurface:** name, surface_category, production_rate_id, production_rate_value (snapshot), quantity, unit, coats, labor_hours, labor_cost, material_cost, hourly_labor_rate (snapshot), application_method (brush_roll/spray), paint_product_id, tier_overrides, is_custom. Quantity source concept explained.
- **ProductionRate:** name, scope, surface_category, production_rate, rate_type, unit_type, coat_multipliers, default_coats. Critical: rate's surface_category must match surface's category.
- **PaintProduct:** name, brand, quality_tier, price_per_gallon, coverage, scope_type, sheen. Material costs come from here, NOT ProductionRate.
- **Substrate:** prep_time_multiplier, paint_time_multiplier, material_multiplier
- **NestedAreaTemplate:** fixed-price add-ons
- **CompanySetting:** wages, burden, margins, cleanup ratios, sundries per scope
- **"Where Each Value Comes From" table** — maps every pricing value to its source model

Note: "crown", "molding", "siding" are NOT valid surface_categories. Crown → "trim", siding → "body".

Source from spec section `03-data-model.md`.

- [ ] **Step 2: Commit**

```bash
git add app/Ai/Instructions/EstimateBuilder/03-data-model.md
git commit -m "feat(ai): add 03-data-model instruction file"
```

---

## Task 4: Write 04-surface-building-guide.md (NEW — core fix)

**Files:**
- Create: `app/Ai/Instructions/EstimateBuilder/04-surface-building-guide.md`

- [ ] **Step 1: Write 04-surface-building-guide.md**

This is the most critical new file. Content covering:

- **Why Surfaces Matter** — each surface is a line item; category controls rates/quantity/unit; name appears on proposal
- **Step 1: Determine Surface Category** — Full decision table mapping "what you're painting" → interior/exterior category
- **Step 2: Find the Right Production Rate** — Filter by scope + category, example with baseboards, lookup-or-create if missing
- **Step 3: Determine Quantity Source** — Table: category → default quantity_source → unit → how calculated. "custom" for cabinets/spindles/unusual. Note about invalid categories.
- **Step 4: Set Quantity Override** — When needed for door_count, window_count, custom
- **Step 5: Give It a Descriptive Name** — Naming rules (Title Case, use rate name, differentiate multiples, specialty names). Good vs Bad table.
- **Step 6: Set Coats** — Defaults and multipliers. Note on application_method for spray jobs.
- **6 Complete Examples:**
  1. Standard interior room from template
  2. Kitchen with custom surfaces (cabinets)
  3. Full house exterior
  4. Front of house only (width+height, wall_count=1)
  5. Partial room (3 walls, brick 4th)
  6. Single accent wall

Source from spec section `04-surface-building-guide.md`.

- [ ] **Step 2: Commit**

```bash
git add app/Ai/Instructions/EstimateBuilder/04-surface-building-guide.md
git commit -m "feat(ai): add 04-surface-building-guide instruction file"
```

---

## Task 5: Write 05-production-rates.md

**Files:**
- Create: `app/Ai/Instructions/EstimateBuilder/05-production-rates.md`

- [ ] **Step 1: Write 05-production-rates.md**

Content covering:

- **What Production Rates Are** — speed data, NOT pricing data
- **5 Rate Types** — sqft_hr, lnft_hr, units_hr, hours_per_unit, cost_per_lnft with formulas, examples, decision tree
- **How Rates Connect to Surface Categories** — Table mapping rate surface_category → available for which surface categories. "This is the most common source of errors."
- **Surface Category Mapping for Non-Standard Items** — Cabinets→trim, shelving→trim, wainscoting→walls, etc.
- **Coat Multipliers** — 1=1.0×, 2=1.8×, 3=2.5×
- **Reference Production Rates** — FULL interior table (17 entries) and exterior table (11 entries) from spec. Prefaced with "check list_production_rates_ai_tool first for company-customized rates."
- **Creating New Rates** — 5-step process: research, cross-reference, create, explain, use
- **Creating Templates from New Rates** — kitchen cabinets, staircase, deck examples

Source from spec section `05-production-rates.md`. Preserves all existing reference tables.

- [ ] **Step 2: Commit**

```bash
git add app/Ai/Instructions/EstimateBuilder/05-production-rates.md
git commit -m "feat(ai): add 05-production-rates instruction file"
```

---

## Task 6: Write 06-tier-pricing.md

**Files:**
- Create: `app/Ai/Instructions/EstimateBuilder/06-tier-pricing.md`

- [ ] **Step 1: Write 06-tier-pricing.md**

Preserve ALL existing tier pricing knowledge word-for-word from the spec:

- What Tier Pricing Is
- What Are Tier Snapshots (with/without explanation)
- MANDATORY rule: every area must have tier snapshots
- The Two Required Steps (set paint products + create snapshots per surface)
- Default Tier Differentiation (Good=1 coat budget, Better=2 mid, Best=2 premium)
- Tool Reference table (4 tools with when-to-use)
- Full Example walkthrough (12 steps for "living room with good better best")

Source from spec section `06-tier-pricing.md`.

- [ ] **Step 2: Commit**

```bash
git add app/Ai/Instructions/EstimateBuilder/06-tier-pricing.md
git commit -m "feat(ai): add 06-tier-pricing instruction file"
```

---

## Task 7: Write 07-tool-reference.md

**Files:**
- Create: `app/Ai/Instructions/EstimateBuilder/07-tool-reference.md`

- [ ] **Step 1: Write 07-tool-reference.md**

All 34 tools documented, grouped by purpose. For each tool: name (exact snake_case), purpose, when to use, key parameters, what it returns, common mistakes.

**Groups:**
- Discovery (10): search_templates, list_production_rates, list_paint_products, list_substrates, list_nested_templates, get_pricing_config, get_estimate_state, get_estimate_summary, get_project_context, list_cost_items
- Creation (3): create_production_rate, create_template, create_nested_template
- Area Building (2): create_area_from_template, create_custom_area
- Surface (5): add_surface_to_area, update_surface, update_surface_tier_overrides, remove_surface, add_addon_to_area
- Area Management (4): remove_area, update_area_dimensions, preview_area_pricing, set_area_package_tiers
- Line Items & Cost Items (3): create_estimate_item, add_cost_item, remove_cost_item
- Paint & Pricing (4): set_estimate_paint_config, set_area_paint_product, set_tier_pricing, update_estimate_settings, recalculate_estimate
- AI Memory (2): save_ai_context, write_estimate_summary

Include the "How Estimates Work" step-by-step and "Modifying Existing Areas" workflows from current instructions.

Source from spec section `07-tool-reference.md`.

- [ ] **Step 2: Commit**

```bash
git add app/Ai/Instructions/EstimateBuilder/07-tool-reference.md
git commit -m "feat(ai): add 07-tool-reference instruction file"
```

---

## Task 8: Write 08-pricing-formula.md and 09-rules-and-reminders.md

**Files:**
- Create: `app/Ai/Instructions/EstimateBuilder/08-pricing-formula.md`
- Create: `app/Ai/Instructions/EstimateBuilder/09-rules-and-reminders.md`

- [ ] **Step 1: Write 08-pricing-formula.md**

Content:
- The 7-step PBP formula with where each value comes from
- Worked example with real numbers (400 sqft walls, 2 coats, interior)
- Cost Items section (surface-level, area-level, estimate-level with tool mapping)

Source from spec section `08-pricing-formula.md`.

- [ ] **Step 2: Write 09-rules-and-reminders.md**

Content:
- Mandatory Rules (always get_estimate_state first, prefer templates, default 2 coats/medium, recalculate after changes)
- Interpreting User Requests (all 10 examples from current instructions)
- Substrates explanation
- Addons explanation
- When User Asks for Something Unusual (6-step process)
- Tier Pricing Reminders (CRITICAL) — the full mandatory rules block including the CORRECTION about per-tier coats, TIER SNAPSHOTS REQUIRED, ALWAYS DO BOTH STEPS, DEFAULT TIER VALUES, TOOL RULES

This file is placed LAST so it's closest to the conversation in the context window.

Source from spec section `09-rules-and-reminders.md`.

- [ ] **Step 3: Commit**

```bash
git add app/Ai/Instructions/EstimateBuilder/08-pricing-formula.md app/Ai/Instructions/EstimateBuilder/09-rules-and-reminders.md
git commit -m "feat(ai): add 08-pricing-formula and 09-rules-and-reminders instruction files"
```

---

## Task 9: Refactor EstimateBuilderAgent — file loader + buildDynamicContext

**Files:**
- Modify: `app/Ai/Agents/EstimateBuilderAgent.php`

- [ ] **Step 1: Replace `instructions()` with file loader**

Replace the entire `instructions()` heredoc method with:
1. Load all `.md` files from `app/Ai/Instructions/EstimateBuilder/` via `glob()` in alphabetical order
2. Concatenate with `"\n\n"` separators
3. Append `$this->buildDynamicContext()`
4. Return the composed string

- [ ] **Step 2: Extract `buildDynamicContext()` private method**

Move the dynamic PHP code from the old `instructions()` into a new `buildDynamicContext()` method. This includes:
1. Company settings (wages, effective rates, margins, cleanup, sundries, burden for both scopes)
2. Custom instructions from `CompanySetting::estimate_builder_instructions`
3. Current estimate state: ID, title, status, project_type, areas summary with pricing, current total
4. Tier pricing status
5. AI context notes from previous sessions
6. Project context (customer, property, deal)

Order matters: company settings → custom instructions → estimate state → AI context → project context.

- [ ] **Step 3: Restore MaxSteps and Timeout attributes**

The current agent has `#[MaxSteps(25)]` and `#[Timeout(180)]`. The full instruction set with 34 tools needs the expanded values:

```php
#[MaxSteps(40)]
#[Timeout(420)]
```

- [ ] **Step 4: Commit**

```bash
git add app/Ai/Agents/EstimateBuilderAgent.php
git commit -m "feat(ai): refactor agent to load modular instruction files"
```

---

## Task 10: Register all 34 tools in agent

**Files:**
- Modify: `app/Ai/Agents/EstimateBuilderAgent.php`

- [ ] **Step 1: Add missing use statements**

Add these 12 missing `use` imports at top of file:
```php
use App\Ai\Tools\Estimate\AddCostItemAiTool;
use App\Ai\Tools\Estimate\CreateEstimateItemAiTool;
use App\Ai\Tools\Estimate\CreateNestedTemplateAiTool;
use App\Ai\Tools\Estimate\CreateProductionRateAiTool;
use App\Ai\Tools\Estimate\CreateTemplateAiTool;
use App\Ai\Tools\Estimate\GetProjectContextAiTool;
use App\Ai\Tools\Estimate\ListCostItemsAiTool;
use App\Ai\Tools\Estimate\RemoveCostItemAiTool;
use App\Ai\Tools\Estimate\SaveAiContextAiTool;
use App\Ai\Tools\Estimate\SetAreaPackageTiersAiTool;
use App\Ai\Tools\Estimate\UpdateSurfaceTierOverridesAiTool;
use App\Ai\Tools\Estimate\WriteEstimateSummaryAiTool;
```

- [ ] **Step 2: Add missing tools to `tools()` method**

Add these 12 tools to the `tools()` return array, in the correct group:

```php
// Discovery tools — add:
new GetProjectContextAiTool($this->estimate),
new ListCostItemsAiTool($this->estimate),

// Creation tools — add:
new CreateProductionRateAiTool,
new CreateTemplateAiTool,
new CreateNestedTemplateAiTool,

// Surface-level tools — add:
new UpdateSurfaceTierOverridesAiTool($this->estimate),

// Area management tools — add:
new SetAreaPackageTiersAiTool($this->estimate),

// Line items and cost items — add:
new CreateEstimateItemAiTool($this->estimate),
new AddCostItemAiTool($this->estimate),
new RemoveCostItemAiTool($this->estimate),

// AI memory & output — add:
new SaveAiContextAiTool($this->estimate),
new WriteEstimateSummaryAiTool($this->estimate),
```

Check each tool's constructor to determine if it takes `$this->estimate` or no args:
- Stateless discovery/creation tools (SearchTemplates, ListProductionRates, ListPaintProducts, ListSubstrates, ListNestedTemplates, GetPricingConfig, CreateProductionRate, CreateTemplate, CreateNestedTemplate): no constructor args
- All other tools: take `$this->estimate`

- [ ] **Step 3: Verify tool count is 34**

After changes, count the tools in the return array to confirm 34 total.

- [ ] **Step 4: Commit**

```bash
git add app/Ai/Agents/EstimateBuilderAgent.php
git commit -m "feat(ai): register all 34 tools in estimate builder agent"
```

---

## Task 11: Run tests and format

- [ ] **Step 1: Run Pint formatter**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 2: Run AI-specific tests**

```bash
php artisan test --compact tests/Feature/Ai/
```

Expected: All 53 tests pass.

- [ ] **Step 3: Run estimate tests**

```bash
php artisan test --compact tests/Feature/Livewire/Estimates/TemplateBuilderTest.php
```

Expected: Tests pass (may hit memory limit on full suite — that's pre-existing).

- [ ] **Step 4: Verify instruction files load correctly**

```bash
php artisan tinker --execute="
\$agent = new \App\Ai\Agents\EstimateBuilderAgent(\App\Models\Estimate::first());
\$instructions = \$agent->instructions();
echo 'Length: ' . strlen(\$instructions) . ' chars' . PHP_EOL;
echo 'Contains role: ' . (str_contains(\$instructions, 'expert painting estimator') ? 'YES' : 'NO') . PHP_EOL;
echo 'Contains pages: ' . (str_contains(\$instructions, 'Template Builder Page') ? 'YES' : 'NO') . PHP_EOL;
echo 'Contains surface guide: ' . (str_contains(\$instructions, 'Step 1: Determine the Surface Category') ? 'YES' : 'NO') . PHP_EOL;
echo 'Contains dynamic: ' . (str_contains(\$instructions, 'Company Settings') ? 'YES' : 'NO') . PHP_EOL;
"
```

Expected: All 4 checks return YES. Length should be ~15,000-25,000 chars.

- [ ] **Step 5: Verify tool count**

```bash
php artisan tinker --execute="
\$agent = new \App\Ai\Agents\EstimateBuilderAgent(\App\Models\Estimate::first());
\$tools = \$agent->tools();
echo 'Tool count: ' . count(iterator_to_array(\$tools)) . PHP_EOL;
"
```

Expected: Tool count: 34

- [ ] **Step 6: Final commit**

```bash
git add -A
git commit -m "feat(ai): complete instruction architecture restructure

Restructured AI estimate builder from monolithic heredoc into 9 modular
instruction files. Registered all 34 tools. Added page descriptions,
surface building guide, naming conventions, and quantity source mapping."
```

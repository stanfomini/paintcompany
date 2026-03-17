<?php

namespace App\Ai\Agents;

use App\Ai\Tools\Estimate\AddAddonToAreaAiTool;
use App\Ai\Tools\Estimate\AddSurfaceToAreaAiTool;
use App\Ai\Tools\Estimate\CreateAreaFromTemplateAiTool;
use App\Ai\Tools\Estimate\CreateCustomAreaAiTool;
use App\Ai\Tools\Estimate\GetEstimateStateAiTool;
use App\Ai\Tools\Estimate\GetEstimateSummaryAiTool;
use App\Ai\Tools\Estimate\GetPricingConfigAiTool;
use App\Ai\Tools\Estimate\ListNestedTemplatesAiTool;
use App\Ai\Tools\Estimate\ListPaintProductsAiTool;
use App\Ai\Tools\Estimate\ListProductionRatesAiTool;
use App\Ai\Tools\Estimate\ListSubstratesAiTool;
use App\Ai\Tools\Estimate\PreviewAreaPricingAiTool;
use App\Ai\Tools\Estimate\RecalculateEstimateAiTool;
use App\Ai\Tools\Estimate\RemoveAreaAiTool;
use App\Ai\Tools\Estimate\RemoveSurfaceAiTool;
use App\Ai\Tools\Estimate\SearchTemplatesAiTool;
use App\Ai\Tools\Estimate\SetAreaPaintProductAiTool;
use App\Ai\Tools\Estimate\SetEstimatePaintConfigAiTool;
use App\Ai\Tools\Estimate\SetTierPricingAiTool;
use App\Ai\Tools\Estimate\UpdateAreaDimensionsAiTool;
use App\Ai\Tools\Estimate\UpdateEstimateSettingsAiTool;
use App\Ai\Tools\Estimate\UpdateSurfaceAiTool;
use App\Models\CompanySetting;
use App\Models\Estimate;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::xAI)]
#[MaxSteps(25)]
#[Timeout(180)]
class EstimateBuilderAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /** @var array<int, array{role: string, content: string}> */
    protected array $conversationHistory = [];

    public function __construct(
        public Estimate $estimate,
    ) {}

    public function model(): string
    {
        return config('ai.providers.xai.model', 'grok-4-0709');
    }

    /**
     * Set conversation history from session.
     *
     * @param  array<int, array{role: string, content: string}>  $history
     */
    public function withHistory(array $history): static
    {
        $this->conversationHistory = $history;

        return $this;
    }

    public function instructions(): Stringable|string
    {
        $settings = CompanySetting::getInstance();
        $companyName = $settings->company_name ?? 'the company';

        // Layer 2: Dynamic company settings
        $interiorWage = $settings->getHourlyWageForScope('interior');
        $interiorEffective = $settings->getEffectiveHourlyRateForScope('interior');
        $interiorMargin = $settings->getMarginPercentForScope('interior');
        $interiorCleanup = $settings->getCleanupHoursRatioForScope('interior');
        $interiorSundries = $settings->getSundriesCostForScope('interior');
        $exteriorWage = $settings->getHourlyWageForScope('exterior');
        $exteriorEffective = $settings->getEffectiveHourlyRateForScope('exterior');
        $exteriorMargin = $settings->getMarginPercentForScope('exterior');
        $exteriorCleanup = $settings->getCleanupHoursRatioForScope('exterior');
        $exteriorSundries = $settings->getSundriesCostForScope('exterior');
        $burdenMultiplier = (float) $settings->payroll_burden_multiplier;

        $customInstructions = $settings->estimate_builder_instructions ?? '';

        // Layer 3: Current estimate context
        $this->estimate->load(['areas.surfaces']);
        $estimateId = $this->estimate->id;
        $estimateTitle = $this->estimate->title ?? 'Untitled';
        $estimateStatus = $this->estimate->status ?? 'draft';
        $projectType = $this->estimate->project_type ?? 'interior';
        $areasCount = $this->estimate->areas->where('parent_area_id', null)->count();
        $currentTotal = (float) ($this->estimate->total ?? 0);

        $areasSummary = '';
        foreach ($this->estimate->areas->where('parent_area_id', null) as $area) {
            $price = $area->getCustomerPrice();
            $areasSummary .= "  - {$area->name} ({$area->scope_type}): \${$price}\n";
        }

        if (empty($areasSummary)) {
            $areasSummary = "  (no areas yet)\n";
        }

        return <<<INSTRUCTIONS
        You are a painting estimate builder assistant for {$companyName}.
        You help users create and modify painting estimates by adding rooms/areas, configuring surfaces, selecting paint products, and managing pricing.

        ## Available Tools

        You have these tools (use exact names):
        - **search_templates_ai_tool** — Find room templates by name
        - **list_production_rates_ai_tool** — List painting production rates
        - **list_paint_products_ai_tool** — List paint products (filter by scope_type, sheen, quality_tier)
        - **list_substrates_ai_tool** — List surface conditions
        - **list_nested_templates_ai_tool** — List fixed-price addons
        - **get_pricing_config_ai_tool** — Company pricing config
        - **get_estimate_state_ai_tool** — Get full estimate with all areas, surfaces, and IDs
        - **update_estimate_settings_ai_tool** — Change title, project_type, notes
        - **get_estimate_summary_ai_tool** — Human-readable summary
        - **create_area_from_template_ai_tool** — Create area from template (preferred)
        - **create_custom_area_ai_tool** — Create area with manual dimensions
        - **add_surface_to_area_ai_tool** — Add surface to an area
        - **update_surface_ai_tool** — Update surface coats, paint, production rate, quantity
        - **remove_surface_ai_tool** — Delete a surface
        - **add_addon_to_area_ai_tool** — Add fixed-price addon to area
        - **remove_area_ai_tool** — Delete an entire area
        - **update_area_dimensions_ai_tool** — Change area dimensions, auto-recalculates surfaces
        - **preview_area_pricing_ai_tool** — Detailed cost breakdown for one area
        - **set_estimate_paint_config_ai_tool** — Set paint globally
        - **set_area_paint_product_ai_tool** — Set paint for specific area
        - **set_tier_pricing_ai_tool** — Set Good/Better/Best tier paint products
        - **recalculate_estimate_ai_tool** — Refresh all totals

        ## How Estimates Work — Step by Step

        1. **Always start** by calling get_estimate_state_ai_tool to see what already exists
        2. **Search for a template** using search_templates_ai_tool (e.g., "living room")
        3. **Create areas** using create_area_from_template_ai_tool (preferred) or create_custom_area_ai_tool
        4. **Set paint products** using set_estimate_paint_config_ai_tool or set_area_paint_product_ai_tool
        5. **Configure tiers** using set_tier_pricing_ai_tool (see Tier Pricing Workflow below)
        6. **Add addons** like closets or repairs using add_addon_to_area_ai_tool
        7. **Recalculate** with recalculate_estimate_ai_tool to finalize totals

        ## Modifying Existing Areas

        When the user wants to change a room that's already on the estimate:
        1. Call get_estimate_state_ai_tool to find the area ID and surface IDs
        2. To change dimensions: use update_area_dimensions_ai_tool (auto-recalculates all surfaces)
        3. To change coats or paint on a surface: use update_surface_ai_tool with the surface_id
        4. To add a missing surface: use add_surface_to_area_ai_tool with the area_id
        5. To remove a surface: use remove_surface_ai_tool with the surface_id
        6. To delete the entire room: use remove_area_ai_tool

        ## Surface Categories

        Surface categories map to auto-calculated quantities from area dimensions:
        - **walls** / **wall** / **body** / **siding** → uses wall_sqft (sqft)
        - **ceiling** / **eaves** → uses ceiling_sqft (sqft)
        - **baseboard** / **trim** / **fascia** / **crown** / **molding** → uses perimeter_lnft (lnft)
        - **doors** / **exterior_doors** / **windows** → count-based (unit, default 1)

        ## PBP Pricing Formula

        1. **Labor Hours** = quantity / production_rate × coats × substrate_multipliers
        2. **Labor Cost** = labor_hours × effective_hourly_rate (wage × payroll burden)
        3. **Cleanup** = labor_hours × cleanup_ratio × effective_rate
        4. **Sundries** = labor_hours × sundries_cost_per_hour
        5. **Materials** = paint cost based on coverage + recycle fee
        6. **COGS** = labor + cleanup + sundries + materials
        7. **Customer Price (OTD)** = COGS / (margin% / 100)

        Example: 50% margin → OTD = COGS / 0.50 = 2× COGS (50% gross profit)

        ## Tier Pricing Workflow (Good / Better / Best)

        The estimate uses tier pricing to show 3 price options. Each tier uses different paint products,
        which changes material cost and therefore the total price. Follow this exact workflow:

        1. Call list_paint_products_ai_tool with quality_tier="good" to get budget paints
        2. Call list_paint_products_ai_tool with quality_tier="better" to get mid-range paints
        3. Call list_paint_products_ai_tool with quality_tier="best" to get premium paints
        4. Call set_tier_pricing_ai_tool for tier="good" with a budget paint_product_id
        5. Call set_tier_pricing_ai_tool for tier="better" with a mid-range paint_product_id
        6. Call set_tier_pricing_ai_tool for tier="best" with a premium paint_product_id

        You can also set different paints per surface type using the category override params:
        walls_paint_id, ceiling_paint_id, trim_paint_id, doors_paint_id

        If the user says "set up good better best" or "tier pricing", do the full workflow above.
        If they say "use Duration for the best tier", just update that one tier.

        ## Substrates (Surface Conditions)

        Substrates modify labor time: prep_time_multiplier, paint_time_multiplier, material_multiplier.
        Examples: previously painted (1.0×), new drywall (1.2×), peeling paint (1.5×)

        ## Addons (Nested Templates)

        Fixed-price items attached to areas: closets, half baths, accent walls, repairs, wallpaper removal.

        ## Interpreting User Requests

        Users describe rooms casually. Interpret naturally:
        - "Add a large living room" → search_templates_ai_tool("living room"), then create_area_from_template_ai_tool with size=large
        - "Paint 3 bedrooms" → create 3 bedroom areas one at a time
        - "The kitchen is 12x14" → create_custom_area_ai_tool or update_area_dimensions_ai_tool if it exists
        - "Use Duration for walls" → list_paint_products_ai_tool to find Duration, then set paint
        - "Good better best pricing" → follow Tier Pricing Workflow above
        - "Remove the hallway" → get_estimate_state_ai_tool, find it, remove_area_ai_tool
        - "Actually that room is bigger" or "change the living room to 14x16" → get_estimate_state_ai_tool to find area_id, then update_area_dimensions_ai_tool
        - "Change the living room to 3 coats" → get_estimate_state_ai_tool to find surface IDs, then update_surface_ai_tool for each surface
        - "Use a different paint for the bedroom ceiling" → get_estimate_state_ai_tool, find the ceiling surface_id, update_surface_ai_tool

        ## Rules

        - **ALWAYS** call get_estimate_state_ai_tool first to see what exists before making changes
        - Prefer templates over custom areas when a template match exists
        - Keep responses concise — say what you did and the resulting price
        - Default to 2 coats and medium size unless specified otherwise
        - Call recalculate_estimate_ai_tool after making multiple changes
        - When the user asks to modify a room, find it by name in the estimate state

        ## Company Settings

        **{$companyName}**

        Interior: Wage \${$interiorWage}/hr | Effective \${$interiorEffective}/hr | Margin {$interiorMargin}% | Cleanup {$interiorCleanup} | Sundries \${$interiorSundries}/hr
        Exterior: Wage \${$exteriorWage}/hr | Effective \${$exteriorEffective}/hr | Margin {$exteriorMargin}% | Cleanup {$exteriorCleanup} | Sundries \${$exteriorSundries}/hr
        Payroll Burden: {$burdenMultiplier}×

        {$customInstructions}

        ## Current Estimate

        - ID: {$estimateId} | Title: {$estimateTitle} | Status: {$estimateStatus} | Type: {$projectType}
        - Areas ({$areasCount}):
        {$areasSummary}
        - Current Total: \${$currentTotal}
        INSTRUCTIONS;
    }

    /**
     * @return Message[]
     */
    public function messages(): iterable
    {
        return collect($this->conversationHistory)
            ->map(fn (array $msg) => new Message($msg['role'], $msg['content']))
            ->all();
    }

    /**
     * @return \Laravel\Ai\Contracts\Tool[]
     */
    public function tools(): iterable
    {
        return [
            // Discovery tools (stateless, no constructor args)
            new SearchTemplatesAiTool,
            new ListProductionRatesAiTool,
            new ListPaintProductsAiTool,
            new ListSubstratesAiTool,
            new ListNestedTemplatesAiTool,
            new GetPricingConfigAiTool,

            // Estimate-level tools
            new GetEstimateStateAiTool($this->estimate),
            new UpdateEstimateSettingsAiTool($this->estimate),
            new GetEstimateSummaryAiTool($this->estimate),

            // Area creation tools
            new CreateAreaFromTemplateAiTool($this->estimate),
            new CreateCustomAreaAiTool($this->estimate),

            // Surface-level tools
            new AddSurfaceToAreaAiTool($this->estimate),
            new UpdateSurfaceAiTool($this->estimate),
            new RemoveSurfaceAiTool($this->estimate),
            new AddAddonToAreaAiTool($this->estimate),

            // Area management tools
            new RemoveAreaAiTool($this->estimate),
            new UpdateAreaDimensionsAiTool($this->estimate),
            new PreviewAreaPricingAiTool($this->estimate),

            // Paint configuration tools
            new SetEstimatePaintConfigAiTool($this->estimate),
            new SetAreaPaintProductAiTool($this->estimate),
            new SetTierPricingAiTool($this->estimate),

            // Recalculate tool
            new RecalculateEstimateAiTool($this->estimate),
        ];
    }
}

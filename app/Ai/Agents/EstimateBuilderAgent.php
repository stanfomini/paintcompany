<?php

namespace App\Ai\Agents;

use App\Ai\Tools\Estimate\AddAddonToAreaAiTool;
use App\Ai\Tools\Estimate\AddCostItemAiTool;
use App\Ai\Tools\Estimate\AddSurfaceToAreaAiTool;
use App\Ai\Tools\Estimate\CreateAreaFromTemplateAiTool;
use App\Ai\Tools\Estimate\CreateCustomAreaAiTool;
use App\Ai\Tools\Estimate\CreateEstimateItemAiTool;
use App\Ai\Tools\Estimate\CreateNestedTemplateAiTool;
use App\Ai\Tools\Estimate\CreateProductionRateAiTool;
use App\Ai\Tools\Estimate\CreateTemplateAiTool;
use App\Ai\Tools\Estimate\ExecuteEstimatePlanAiTool;
use App\Ai\Tools\Estimate\GetEstimateStateAiTool;
use App\Ai\Tools\Estimate\GetEstimateSummaryAiTool;
use App\Ai\Tools\Estimate\GetPricingConfigAiTool;
use App\Ai\Tools\Estimate\GetProjectContextAiTool;
use App\Ai\Tools\Estimate\ListCostItemsAiTool;
use App\Ai\Tools\Estimate\ListNestedTemplatesAiTool;
use App\Ai\Tools\Estimate\ListPaintProductsAiTool;
use App\Ai\Tools\Estimate\ListProductionRatesAiTool;
use App\Ai\Tools\Estimate\ListSubstratesAiTool;
use App\Ai\Tools\Estimate\PreviewAreaPricingAiTool;
use App\Ai\Tools\Estimate\RecalculateEstimateAiTool;
use App\Ai\Tools\Estimate\RemoveAreaAiTool;
use App\Ai\Tools\Estimate\RemoveCostItemAiTool;
use App\Ai\Tools\Estimate\RemoveSurfaceAiTool;
use App\Ai\Tools\Estimate\SaveAiContextAiTool;
use App\Ai\Tools\Estimate\SearchTemplatesAiTool;
use App\Ai\Tools\Estimate\SetAreaPackageTiersAiTool;
use App\Ai\Tools\Estimate\SetAreaPaintProductAiTool;
use App\Ai\Tools\Estimate\SetEstimatePaintConfigAiTool;
use App\Ai\Tools\Estimate\SetSurfacePackageTiersAiTool;
use App\Ai\Tools\Estimate\SetTierPricingAiTool;
use App\Ai\Tools\Estimate\UpdateAreaDimensionsAiTool;
use App\Ai\Tools\Estimate\UpdateEstimateSettingsAiTool;
use App\Ai\Tools\Estimate\UpdateSurfaceAiTool;
use App\Ai\Tools\Estimate\UpdateSurfaceTierOverridesAiTool;
use App\Ai\Tools\Estimate\WriteEstimateSummaryAiTool;
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
#[MaxSteps(80)]
#[Timeout(600)]
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
        // Load static instruction files in alphabetical order (numbered prefix ensures correct ordering)
        $instructionsPath = app_path('Ai/Instructions/EstimateBuilder');
        $sections = [];

        foreach (glob("{$instructionsPath}/*.md") as $file) {
            $sections[] = file_get_contents($file);
        }

        // Append dynamic context (company settings, estimate state, etc.)
        $sections[] = $this->buildDynamicContext();

        return implode("\n\n", $sections);
    }

    /**
     * Build dynamic context that changes per-request.
     * Order: company settings → custom instructions → estimate state → AI context → project context.
     */
    protected function buildDynamicContext(): string
    {
        $settings = CompanySetting::getInstance();
        $companyName = $settings->company_name ?? 'the company';

        // Company settings
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

        // Custom instructions from company settings
        $customInstructions = $settings->estimate_builder_instructions ?? '';

        // Current estimate context
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

        // Build tier pricing status
        $tierStatus = 'Not configured';
        if ($this->estimate->hasPaintTiers()) {
            $tiers = $this->estimate->paint_tiers;
            $tierParts = [];
            foreach (['good', 'better', 'best'] as $tier) {
                $config = $tiers[$tier] ?? null;
                if ($config) {
                    $paintId = $config['default_paint_product_id'] ?? null;
                    $paint = $paintId ? \App\Models\PaintProduct::find($paintId) : null;
                    $tierParts[] = ucfirst($tier).': '.($paint?->name ?? 'not set');
                }
            }
            $tierStatus = implode(' | ', $tierParts) ?: 'Configured but empty';
        }

        // Load AI context if it exists
        $aiContext = $this->estimate->ai_context;
        $aiContextSection = $aiContext
            ? "\n\n## AI Notes (from previous sessions)\n\n{$aiContext}"
            : '';

        // Load project context summary
        $projectContextSection = '';
        $deal = $this->estimate->deal;
        if ($deal) {
            $customer = $deal->customer;
            $property = $deal->property;
            $parts = [];
            if ($customer) {
                $parts[] = "Customer: {$customer->name}";
            }
            if ($property) {
                $addr = $property->full_address ?? $property->address ?? '';
                $sqft = $property->sqft ? " ({$property->sqft} sqft)" : '';
                $year = $property->year_built ? ", built {$property->year_built}" : '';
                if ($addr) {
                    $parts[] = "Property: {$addr}{$sqft}{$year}";
                }
            }
            if (! empty($parts)) {
                $projectContextSection = "\n\n## Project Context\n\n".implode("\n", $parts);
            }
        }

        return <<<CONTEXT
        ## Company Settings

        **{$companyName}**

        Interior: Wage \${$interiorWage}/hr | Effective \${$interiorEffective}/hr | Margin {$interiorMargin}% | Cleanup {$interiorCleanup} | Sundries \${$interiorSundries}/hr
        Exterior: Wage \${$exteriorWage}/hr | Effective \${$exteriorEffective}/hr | Margin {$exteriorMargin}% | Cleanup {$exteriorCleanup} | Sundries \${$exteriorSundries}/hr
        Payroll Burden: {$burdenMultiplier}×

        {$customInstructions}

        ## Current Estimate

        - ID: {$estimateId} | Title: {$estimateTitle} | Status: {$estimateStatus} | Type: {$projectType}
        - Tier Pricing: {$tierStatus}
        - Areas ({$areasCount}):
        {$areasSummary}
        - Current Total: \${$currentTotal}
        {$aiContextSection}
        {$projectContextSection}
        CONTEXT;
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
            new GetProjectContextAiTool($this->estimate),

            // Creation tools (permanent, reusable)
            new CreateProductionRateAiTool,
            new CreateTemplateAiTool,
            new CreateNestedTemplateAiTool,

            // Area creation tools
            new CreateAreaFromTemplateAiTool($this->estimate),
            new CreateCustomAreaAiTool($this->estimate),

            // Surface-level tools
            new AddSurfaceToAreaAiTool($this->estimate),
            new UpdateSurfaceAiTool($this->estimate),
            new UpdateSurfaceTierOverridesAiTool($this->estimate),
            new RemoveSurfaceAiTool($this->estimate),
            new AddAddonToAreaAiTool($this->estimate),

            // Area management tools
            new RemoveAreaAiTool($this->estimate),
            new UpdateAreaDimensionsAiTool($this->estimate),
            new PreviewAreaPricingAiTool($this->estimate),
            new SetAreaPackageTiersAiTool($this->estimate),
            new SetSurfacePackageTiersAiTool($this->estimate),

            // Line items and cost items
            new CreateEstimateItemAiTool($this->estimate),
            new AddCostItemAiTool($this->estimate),
            new RemoveCostItemAiTool($this->estimate),
            new ListCostItemsAiTool($this->estimate),

            // Paint configuration tools
            new SetEstimatePaintConfigAiTool($this->estimate),
            new SetAreaPaintProductAiTool($this->estimate),
            new SetTierPricingAiTool($this->estimate),

            // AI memory & output
            new SaveAiContextAiTool($this->estimate),
            new WriteEstimateSummaryAiTool($this->estimate),

            // Recalculate tool
            new RecalculateEstimateAiTool($this->estimate),

            // Batch execution (mega-tool)
            new ExecuteEstimatePlanAiTool($this->estimate),
        ];
    }
}

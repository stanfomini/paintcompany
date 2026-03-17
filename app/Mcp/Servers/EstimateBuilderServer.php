<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\Estimate\EstimateTemplatesResource;
use App\Mcp\Resources\Estimate\NestedAreaTemplatesResource;
use App\Mcp\Resources\Estimate\PaintProductCatalogResource;
use App\Mcp\Resources\Estimate\PricingConfigResource;
use App\Mcp\Resources\Estimate\ProductionRatesResource;
use App\Mcp\Resources\Estimate\SubstrateConditionsResource;
use App\Mcp\Tools\Estimate\AddAddonToAreaTool;
use App\Mcp\Tools\Estimate\AddSurfaceToAreaTool;
use App\Mcp\Tools\Estimate\CreateAreaFromTemplateTool;
use App\Mcp\Tools\Estimate\CreateCustomAreaTool;
use App\Mcp\Tools\Estimate\GetEstimateStateTool;
use App\Mcp\Tools\Estimate\GetEstimateSummaryTool;
use App\Mcp\Tools\Estimate\PreviewAreaPricingTool;
use App\Mcp\Tools\Estimate\RecalculateEstimateTool;
use App\Mcp\Tools\Estimate\RemoveAreaTool;
use App\Mcp\Tools\Estimate\RemoveSurfaceTool;
use App\Mcp\Tools\Estimate\SetAreaPaintProductTool;
use App\Mcp\Tools\Estimate\SetEstimatePaintConfigTool;
use App\Mcp\Tools\Estimate\SetTierPricingTool;
use App\Mcp\Tools\Estimate\UpdateAreaDimensionsTool;
use App\Mcp\Tools\Estimate\UpdateEstimateSettingsTool;
use App\Mcp\Tools\Estimate\UpdateSurfaceTool;
use Laravel\Mcp\Server;

class EstimateBuilderServer extends Server
{
    protected string $name = 'Estimate Builder';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Build and modify painting estimates for a painting company CRM.

        Estimates contain areas (rooms or exterior sections), each with surfaces (walls, ceiling, trim, doors)
        that are priced using PBP (Painting Business Pricing) production rates. The formula is:
        OTD = COGS / (margin% / 100), where COGS = labor + cleanup + sundries + materials + taxes.

        **Workflow:**
        1. Read the estimate_templates resource to discover available room templates with preset sizes (S/M/L).
        2. Use create_area_from_template for quick room addition, or create_custom_area for manual dimensions.
        3. Each area auto-generates surfaces from template ratios. Customize with add_surface_to_area, update_surface, or remove_surface.
        4. Add fixed-price addons (closets, accent walls) using add_addon_to_area from the nested_area_templates resource.
        5. Configure paint products globally (set_estimate_paint_config), per-area (set_area_paint_product), or per-tier (set_tier_pricing).
        6. Use preview_area_pricing to see detailed cost breakdowns per area.
        7. Call recalculate_estimate after bulk changes to refresh totals.

        **Resources:** Read production_rates, paint_products, substrates, addons, and pricing_config for available options.
        **Pricing:** Interior and exterior have separate wages, margins, cleanup ratios, and sundries. Each area uses its scope_type.
        **Tiers:** Good/Better/Best tiers allow different paint products at different price points.
    MARKDOWN;

    /** @var array<int, class-string<\Laravel\Mcp\Server\Tool>> */
    protected array $tools = [
        GetEstimateStateTool::class,
        UpdateEstimateSettingsTool::class,
        RecalculateEstimateTool::class,
        GetEstimateSummaryTool::class,
        CreateAreaFromTemplateTool::class,
        CreateCustomAreaTool::class,
        AddSurfaceToAreaTool::class,
        UpdateSurfaceTool::class,
        RemoveSurfaceTool::class,
        AddAddonToAreaTool::class,
        RemoveAreaTool::class,
        UpdateAreaDimensionsTool::class,
        PreviewAreaPricingTool::class,
        SetEstimatePaintConfigTool::class,
        SetAreaPaintProductTool::class,
        SetTierPricingTool::class,
    ];

    /** @var array<int, class-string<\Laravel\Mcp\Server\Resource>> */
    protected array $resources = [
        EstimateTemplatesResource::class,
        ProductionRatesResource::class,
        PaintProductCatalogResource::class,
        SubstrateConditionsResource::class,
        NestedAreaTemplatesResource::class,
        PricingConfigResource::class,
    ];
}

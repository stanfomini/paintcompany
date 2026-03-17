<?php

namespace App\Mcp\Resources\Estimate;

use App\Services\EstimateAiService;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class PaintProductCatalogResource extends Resource
{
    protected string $name = 'paint_product_catalog';

    protected string $uri = 'estimate://paint-products';

    protected string $mimeType = 'application/json';

    protected string $description = <<<'MARKDOWN'
        All active paint products with brand, sheen, quality tier, price per gallon, and coverage. Use product IDs with set_estimate_paint_config, set_area_paint_product, set_tier_pricing, or update_surface tools.
    MARKDOWN;

    public function handle(Request $request, EstimateAiService $service): Response
    {
        return Response::json($service->listPaintProducts());
    }
}

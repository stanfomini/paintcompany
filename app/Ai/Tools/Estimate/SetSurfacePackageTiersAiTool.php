<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SetSurfacePackageTiersAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Control which Good/Better/Best tiers include this surface. Create tier-exclusive surfaces (e.g., crown molding only in Best). Surfaces excluded from a tier are not priced in that tier.';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $tiers = isset($request['tiers']) ? (array) $request['tiers'] : null;

            $result = $service->setSurfacePackageTiers(
                surfaceId: (int) $request['surface_id'],
                tiers: $tiers,
                estimate: $this->estimate,
            );

            return json_encode($result);
        } catch (\Throwable $e) {
            return "Error: {$e->getMessage()}";
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'surface_id' => $schema->integer()->description('ID of the surface to configure')->required(),
            'tiers' => $schema->array()->description('Tier names this surface appears in, e.g. ["better","best"]. Null or empty = all tiers (default).'),
        ];
    }
}

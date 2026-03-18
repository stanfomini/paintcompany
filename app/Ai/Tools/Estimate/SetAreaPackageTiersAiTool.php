<?php

namespace App\Ai\Tools\Estimate;

use App\Models\Estimate;
use App\Services\EstimateAiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class SetAreaPackageTiersAiTool implements Tool
{
    public function __construct(
        protected Estimate $estimate,
    ) {}

    public function description(): Stringable|string
    {
        return 'Control which Good/Better/Best tiers include this area. Create tier-exclusive areas (e.g., accent wall only in Best).';
    }

    public function handle(Request $request): Stringable|string
    {
        $service = app(EstimateAiService::class);

        try {
            $tiers = isset($request['tiers']) ? (array) $request['tiers'] : null;

            $result = $service->setAreaPackageTiers(
                areaId: (int) $request['area_id'],
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
            'area_id' => $schema->integer()->description('ID of the area to configure')->required(),
            'tiers' => $schema->array()->description('Tier names this area appears in, e.g. ["good","better","best"]. Null or empty = all tiers.'),
        ];
    }
}

<?php

namespace App\Ai\Tools;

use App\Models\PaintColor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class PaintColorLookupTool implements Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string
    {
        return 'Look up paint color hex values by name or code. Searches the paint color database for matches across Sherwin-Williams, Benjamin Moore, Behr, and PPG brands. You can include brand names in the query (e.g., "BM White Dove", "SW-7069"). Handles grey/gray variants and hyphen/space normalization in codes. Returns the color name, code, and hex value for accurate color visualization.';
    }

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string
    {
        $query = $request['query'];
        $brand = $request['brand'] ?? null;

        $colors = PaintColor::query()
            ->with('brand')
            ->active()
            ->search($query)
            ->when($brand, function ($q) use ($brand) {
                $q->whereHas('brand', function ($bq) use ($brand) {
                    $bq->where('name', 'like', "%{$brand}%")
                        ->orWhere('code', 'like', "%{$brand}%");
                });
            })
            ->limit(15)
            ->get();

        if ($colors->isEmpty()) {
            return "No paint colors found matching \"{$query}\". Try a different search term or check the spelling.";
        }

        $results = $colors->map(function (PaintColor $color) {
            return "{$color->brand->name} {$color->name} ({$color->code}): {$color->hex_value}";
        });

        return $results->implode("\n");
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('The paint color name or code to search for (e.g., "White Dove", "SW-7069", "Iron Ore")')
                ->required(),
            'brand' => $schema->string()
                ->description('Optional brand name or code to filter results (e.g., "Benjamin Moore", "SW", "Behr")'),
        ];
    }
}

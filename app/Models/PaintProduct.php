<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaintProduct extends Model
{
    use HasFactory;

    public const SHEEN_FLAT = 'flat';

    public const SHEEN_MATTE = 'matte';

    public const SHEEN_EGGSHELL = 'eggshell';

    public const SHEEN_SATIN = 'satin';

    public const SHEEN_SEMI_GLOSS = 'semi-gloss';

    public const SHEEN_GLOSS = 'gloss';

    public const TYPE_PAINT = 'paint';

    public const TYPE_PRIMER = 'primer';

    public const TYPE_STAIN = 'stain';

    public const TYPE_SEALER = 'sealer';

    public const SCOPE_INTERIOR = 'interior';

    public const SCOPE_EXTERIOR = 'exterior';

    public const SCOPE_BOTH = 'both';

    protected $fillable = [
        'paint_brand_id',
        'name',
        'code',
        'description',
        'sheen',
        'product_type',
        'scope_type',
        'surface_type',
        'price_per_gallon',
        'price_per_five_gallon',
        'coverage_sqft_brush_roll',
        'coverage_sqft_spray',
        'is_premium',
        'is_low_voc',
        'is_self_priming',
        'requires_tinting',
        'recommended_coats',
        'second_coat_coverage_multiplier',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_per_gallon' => 'decimal:2',
            'price_per_five_gallon' => 'decimal:2',
            'coverage_sqft_brush_roll' => 'integer',
            'coverage_sqft_spray' => 'integer',
            'is_premium' => 'boolean',
            'is_low_voc' => 'boolean',
            'is_self_priming' => 'boolean',
            'requires_tinting' => 'boolean',
            'recommended_coats' => 'integer',
            'second_coat_coverage_multiplier' => 'decimal:2',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the brand for this product.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(PaintBrand::class, 'paint_brand_id');
    }

    /**
     * Get area paint selections using this product.
     */
    public function areaSelections(): HasMany
    {
        return $this->hasMany(EstimateAreaPaintSelection::class);
    }

    /**
     * Scope to only active products.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope by scope type (interior, exterior, or both).
     */
    public function scopeForScope(Builder $query, string $scopeType): Builder
    {
        return $query->whereIn('scope_type', [$scopeType, self::SCOPE_BOTH]);
    }

    /**
     * Scope to interior products.
     */
    public function scopeInterior(Builder $query): Builder
    {
        return $query->forScope(self::SCOPE_INTERIOR);
    }

    /**
     * Scope to exterior products.
     */
    public function scopeExterior(Builder $query): Builder
    {
        return $query->forScope(self::SCOPE_EXTERIOR);
    }

    /**
     * Scope by sheen.
     */
    public function scopeSheen(Builder $query, string $sheen): Builder
    {
        return $query->where('sheen', $sheen);
    }

    /**
     * Scope by product type.
     */
    public function scopeProductType(Builder $query, string $type): Builder
    {
        return $query->where('product_type', $type);
    }

    /**
     * Get the full product name with brand.
     */
    public function getFullNameAttribute(): string
    {
        return $this->brand ? "{$this->brand->name} {$this->name}" : $this->name;
    }

    /**
     * Get coverage based on application method.
     */
    public function getCoverage(string $applicationMethod = 'brush_roll'): int
    {
        return match ($applicationMethod) {
            'spray' => $this->coverage_sqft_spray,
            default => $this->coverage_sqft_brush_roll,
        };
    }

    /**
     * Calculate gallons needed for given sqft and coats.
     *
     * @return array{gallons: float, gallons_rounded: int}
     */
    public function calculateGallons(float $sqft, int $coats = 2, string $applicationMethod = 'brush_roll'): array
    {
        $coverage = $this->getCoverage($applicationMethod);

        // Adjust coverage for subsequent coats
        $effectiveCoverage = $coverage;
        if ($coats > 1) {
            $effectiveCoverage = $coverage * (float) $this->second_coat_coverage_multiplier;
        }

        $gallons = ($sqft * $coats) / $effectiveCoverage;

        return [
            'gallons' => round($gallons, 2),
            'gallons_rounded' => (int) ceil($gallons),
        ];
    }

    /**
     * Calculate total cost for given sqft and coats.
     *
     * @return array{gallons: float, gallons_rounded: int, paint_cost: float, recycle_fee: float, total_cost: float}
     */
    public function calculateCost(float $sqft, int $coats = 2, string $applicationMethod = 'brush_roll', ?float $recycleFeePerGallon = null): array
    {
        $recycleFeePerGallon ??= (float) CompanySetting::getInstance()->recycle_fee_per_gallon;
        $gallonCalc = $this->calculateGallons($sqft, $coats, $applicationMethod);

        $paintCost = $gallonCalc['gallons_rounded'] * (float) $this->price_per_gallon;
        $recycleFee = $gallonCalc['gallons_rounded'] * $recycleFeePerGallon;

        return [
            'gallons' => $gallonCalc['gallons'],
            'gallons_rounded' => $gallonCalc['gallons_rounded'],
            'paint_cost' => round($paintCost, 2),
            'recycle_fee' => round($recycleFee, 2),
            'total_cost' => round($paintCost + $recycleFee, 2),
        ];
    }

    /**
     * Get available sheens.
     *
     * @return array<string, string>
     */
    public static function getSheens(): array
    {
        return [
            self::SHEEN_FLAT => 'Flat',
            self::SHEEN_MATTE => 'Matte',
            self::SHEEN_EGGSHELL => 'Eggshell',
            self::SHEEN_SATIN => 'Satin',
            self::SHEEN_SEMI_GLOSS => 'Semi-Gloss',
            self::SHEEN_GLOSS => 'Gloss',
        ];
    }

    /**
     * Get available product types.
     *
     * @return array<string, string>
     */
    public static function getProductTypes(): array
    {
        return [
            self::TYPE_PAINT => 'Paint',
            self::TYPE_PRIMER => 'Primer',
            self::TYPE_STAIN => 'Stain',
            self::TYPE_SEALER => 'Sealer',
        ];
    }
}

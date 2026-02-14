<?php

namespace Database\Seeders;

use App\Models\CrewProfile;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\Job;
use App\Models\PaintBrand;
use App\Models\PaintProduct;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SampleEstimateSeeder extends Seeder
{
    public const ESTIMATE_NUMBER = 'SAMPLE-ONBOARDING-001';

    /**
     * Hardcoded paint data so this works on any server without PaintCatalogSeeder.
     * If PaintProduct records exist, we also set paint_product_id for rich display.
     */
    private const PAINT_DATA = [
        'walls' => ['brand' => 'sherwin_williams', 'type' => 'duration_home', 'price' => 79.99],
        'ceiling' => ['brand' => 'sherwin_williams', 'type' => 'promar_ceiling', 'price' => 34.99],
        'trim' => ['brand' => 'sherwin_williams', 'type' => 'emerald_urethane_trim', 'price' => 89.99],
    ];

    /**
     * Idempotent: returns existing sample estimate or creates one.
     * Used by crew onboarding to display a realistic work order
     * without exposing real customer data.
     */
    public static function ensureExists(): Estimate
    {
        $existing = Estimate::where('estimate_number', self::ESTIMATE_NUMBER)
            ->with([
                'areas.surfaces.productionRate',
                'areas.surfaces.paintProduct.brand',
                'areas.addons.nestedAreaTemplate',
                'areas.substrate',
                'deal.property',
                'deal.customer.user',
                'job.crews.crewProfile',
            ])
            ->first();

        if ($existing) {
            // Self-heal: update if data is stale (pre-v2 seeder)
            $needsRefresh = $existing->actual_painters === null
                || ! isset($existing->actual_painters[0]['hourly_rate'])
                || (float) $existing->actual_painters[0]['hourly_rate'] === 35.0;

            // Also heal if job is missing (created before job code was added)
            $needsJob = ! $existing->job;

            if ($needsRefresh) {
                self::refreshSampleData($existing);
            }

            if ($needsJob) {
                self::ensureSampleJob($existing);
            }

            if ($needsRefresh || $needsJob) {
                $existing->refresh()->load([
                    'areas.surfaces.productionRate',
                    'areas.surfaces.paintProduct.brand',
                    'areas.addons.nestedAreaTemplate',
                    'areas.substrate',
                    'deal.property',
                    'deal.customer.user',
                    'job.crews.crewProfile',
                ]);
            }

            return $existing;
        }

        return self::createSampleEstimate();
    }

    public function run(): void
    {
        self::ensureExists();
    }

    /**
     * Look up PaintProduct IDs if the catalog is seeded. Returns null-safe array.
     */
    private static function lookupPaintProducts(): array
    {
        $swBrand = PaintBrand::where('code', 'SW')->first();

        return [
            'walls' => $swBrand ? PaintProduct::where('paint_brand_id', $swBrand->id)->where('name', 'Duration Home')->first() : null,
            'ceiling' => $swBrand ? PaintProduct::where('paint_brand_id', $swBrand->id)->where('name', 'ProMar Ceiling Paint')->first() : null,
            'trim' => $swBrand ? PaintProduct::where('paint_brand_id', $swBrand->id)->where('name', 'Emerald Urethane Trim')->first() : null,
        ];
    }

    /**
     * Build actual_paint_costs keyed to match materialsWithCosts computed property.
     * Uses PaintProduct IDs when available, legacy string keys as fallback.
     */
    private static function buildActualPaintCosts(array $products): array
    {
        $costs = [];

        // Crew discount prices per gallon
        $discounts = ['walls' => 55.00, 'ceiling' => 22.00, 'trim' => 62.00];

        foreach ($discounts as $type => $discountPrice) {
            if ($products[$type]) {
                // PaintProduct exists - key by ID (matches materialsWithCosts product path)
                $costs[(string) $products[$type]->id] = $discountPrice;
            } else {
                // No PaintProduct - key by legacy string (matches materialsWithCosts legacy path)
                $paint = self::PAINT_DATA[$type];
                $costs["{$paint['brand']}_{$paint['type']}"] = $discountPrice;
            }
        }

        return $costs;
    }

    /**
     * Update an existing sample estimate with correct paint data and painter rates.
     */
    private static function refreshSampleData(Estimate $estimate): void
    {
        $products = self::lookupPaintProducts();

        // Update surfaces with correct paint data
        foreach ($estimate->areas as $area) {
            foreach ($area->surfaces as $surface) {
                $paintKey = match (true) {
                    str_contains(strtolower($surface->name), 'wall') => 'walls',
                    str_contains(strtolower($surface->name), 'ceiling') => 'ceiling',
                    str_contains(strtolower($surface->name), 'baseboard'),
                    str_contains(strtolower($surface->name), 'door') => 'trim',
                    default => null,
                };

                if ($paintKey) {
                    $paint = self::PAINT_DATA[$paintKey];
                    $product = $products[$paintKey];
                    $gallons = (float) ($surface->paint_gallons ?? 1);
                    $pricePerGallon = $product ? (float) $product->price_per_gallon : $paint['price'];

                    $surface->paint_product_id = $product?->id;
                    $surface->paint_brand = $paint['brand'];
                    $surface->paint_type = $paint['type'];
                    $surface->paint_cost = round($gallons * $pricePerGallon, 2);
                    $surface->material_cost = $surface->paint_cost;
                    $surface->total = round($surface->labor_cost + $surface->material_cost, 2);
                    $surface->save();
                }
            }
        }

        // Fix painter rates
        $estimate->actual_painters = [
            ['name' => 'Mike Rodriguez', 'hourly_rate' => 27.00, 'hours' => 8, 'notes' => 'Lead painter - walls & ceilings'],
            ['name' => 'Carlos Mendez', 'hourly_rate' => 23.00, 'hours' => 8, 'notes' => 'Trim, baseboards & doors'],
        ];

        $estimate->actual_paint_costs = self::buildActualPaintCosts($products);
        $estimate->save();
    }

    /**
     * Create the job + crew records if they're missing on an existing sample estimate.
     */
    private static function ensureSampleJob(Estimate $estimate): void
    {
        $deal = $estimate->deal;

        $job = Job::create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
            'job_number' => 'JOB-SAMPLE-001',
            'stage' => 'in_progress',
            'title' => $deal->title,
            'description' => $estimate->description,
            'required_crew_size' => 2,
            'scheduled_date' => now()->addDays(3),
        ]);

        $crewUser = User::firstOrCreate(
            ['email' => 'sample-crew@system.local'],
            [
                'name' => 'Mike Rodriguez',
                'type' => 'crew',
                'password' => bcrypt('not-a-real-password'),
            ]
        );

        CrewProfile::updateOrCreate(
            ['user_id' => $crewUser->id],
            [
                'company_name' => 'Rodriguez Painting LLC',
                'phone' => '(303) 555-0200',
                'crew_size' => 2,
                'specialties' => ['interior', 'exterior'],
                'hourly_rate' => 35.00,
                'bonus_percent' => 2.00,
                'bio' => '10+ years of residential painting experience in the Denver metro area.',
            ]
        );

        // Only insert if not already attached
        if (! DB::table('job_crew')->where('job_id', $job->id)->where('crew_user_id', $crewUser->id)->exists()) {
            DB::table('job_crew')->insert([
                'job_id' => $job->id,
                'crew_user_id' => $crewUser->id,
                'status' => 'accepted',
                'dispatched_at' => now()->subDays(5),
                'responded_at' => now()->subDays(4),
                'notes' => 'Ready to go!',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private static function createSampleEstimate(): Estimate
    {
        // Create an internal system user to own the sample data
        $systemUser = User::firstOrCreate(
            ['email' => 'sample-system@system.local'],
            [
                'name' => 'System Sample',
                'type' => 'internal',
                'password' => bcrypt('not-a-real-password'),
            ]
        );

        // Create archived customer (hidden from active customer lists)
        $customer = Customer::create([
            'created_by' => $systemUser->id,
            'first_name' => 'Jane',
            'last_name' => 'Smith',
            'email' => 'sample-onboarding@system.local',
            'phone' => '(303) 555-0100',
            'address' => '123 Main Street',
            'city' => 'Denver',
            'state' => 'CO',
            'zip' => '80202',
            'status' => 'archived',
        ]);

        // The Customer model auto-creates a Property on save via booted() hook.
        // Grab it and update with additional details.
        $property = $customer->properties()->first();
        if ($property) {
            $property->update([
                'type' => 'residential',
                'square_footage' => 1800,
            ]);
        } else {
            $property = Property::create([
                'customer_id' => $customer->id,
                'address' => '123 Main Street',
                'city' => 'Denver',
                'state' => 'CO',
                'zip' => '80202',
                'type' => 'residential',
                'square_footage' => 1800,
            ]);
        }

        // Create completed deal (hidden from kanban board)
        $deal = Deal::create([
            'customer_id' => $customer->id,
            'property_id' => $property->id,
            'created_by' => $systemUser->id,
            'title' => '4-Room Interior Repaint - 123 Main Street',
            'stage' => 'completed',
            'estimated_value' => 2262.00,
            'actual_value' => 2262.00,
        ]);

        // Create the estimate
        $estimate = Estimate::create([
            'deal_id' => $deal->id,
            'created_by' => $systemUser->id,
            'estimate_number' => self::ESTIMATE_NUMBER,
            'title' => '4-Room Interior Repaint',
            'description' => 'Full interior repaint of living room, kitchen, master bedroom, and bathroom. Includes walls, ceilings, baseboards, and doors.',
            'status' => 'accepted',
            'project_type' => 'interior',
            'estimator_mode' => 'template',
            'sent_at' => now()->subWeeks(2),
            'accepted_at' => now()->subWeek(),
            'tax_rate' => 0,
            'discount_amount' => 0,
        ]);

        // Look up PaintProducts (optional - works without them via legacy fallback)
        $products = self::lookupPaintProducts();

        // Effective rate: $25/hr base × 1.35 burden = $33.75/hr
        $effectiveRate = 33.75;

        // ===== LIVING ROOM (15x12x9) =====
        $livingRoom = EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'scope_type' => 'interior',
            'length' => 15,
            'width' => 12,
            'height' => 9,
            'wall_sqft' => 486,    // 2*(15+12)*9
            'ceiling_sqft' => 180, // 15*12
            'perimeter_lnft' => 54,
            'baseboard_lnft' => 54,
            'sort_order' => 1,
        ]);

        self::createSurface($livingRoom, 'Walls', 'sqft', 486, 200, 2, $effectiveRate, 1, 'walls', $products);
        self::createSurface($livingRoom, 'Ceiling', 'sqft', 180, 250, 1, $effectiveRate, 2, 'ceiling', $products);
        self::createSurface($livingRoom, 'Baseboards', 'lnft', 54, 60, 1, $effectiveRate, 3, 'trim', $products);
        self::createSurface($livingRoom, 'Door', 'unit', 1, 2, 2, $effectiveRate, 4, 'trim', $products);

        // ===== KITCHEN (12x10x9) =====
        $kitchen = EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Kitchen',
            'scope_type' => 'interior',
            'length' => 12,
            'width' => 10,
            'height' => 9,
            'wall_sqft' => 396,    // 2*(12+10)*9
            'ceiling_sqft' => 120, // 12*10
            'perimeter_lnft' => 44,
            'baseboard_lnft' => 44,
            'sort_order' => 2,
        ]);

        self::createSurface($kitchen, 'Walls', 'sqft', 396, 200, 2, $effectiveRate, 1, 'walls', $products);
        self::createSurface($kitchen, 'Ceiling', 'sqft', 120, 250, 1, $effectiveRate, 2, 'ceiling', $products);
        self::createSurface($kitchen, 'Baseboards', 'lnft', 44, 60, 1, $effectiveRate, 3, 'trim', $products);

        // ===== MASTER BEDROOM (14x13x9) =====
        $masterBedroom = EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Master Bedroom',
            'scope_type' => 'interior',
            'length' => 14,
            'width' => 13,
            'height' => 9,
            'wall_sqft' => 486,    // 2*(14+13)*9
            'ceiling_sqft' => 182, // 14*13
            'perimeter_lnft' => 54,
            'baseboard_lnft' => 54,
            'sort_order' => 3,
        ]);

        self::createSurface($masterBedroom, 'Walls', 'sqft', 486, 200, 2, $effectiveRate, 1, 'walls', $products);
        self::createSurface($masterBedroom, 'Ceiling', 'sqft', 182, 250, 1, $effectiveRate, 2, 'ceiling', $products);
        self::createSurface($masterBedroom, 'Baseboards', 'lnft', 54, 60, 1, $effectiveRate, 3, 'trim', $products);
        self::createSurface($masterBedroom, 'Doors', 'unit', 2, 2, 2, $effectiveRate, 4, 'trim', $products);

        // ===== BATHROOM (8x6x9) =====
        $bathroom = EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Bathroom',
            'scope_type' => 'interior',
            'length' => 8,
            'width' => 6,
            'height' => 9,
            'wall_sqft' => 252,   // 2*(8+6)*9
            'ceiling_sqft' => 48, // 8*6
            'perimeter_lnft' => 28,
            'baseboard_lnft' => 28,
            'sort_order' => 4,
        ]);

        self::createSurface($bathroom, 'Walls', 'sqft', 252, 200, 2, $effectiveRate, 1, 'walls', $products);
        self::createSurface($bathroom, 'Ceiling', 'sqft', 48, 250, 1, $effectiveRate, 2, 'ceiling', $products);
        self::createSurface($bathroom, 'Baseboards', 'lnft', 28, 60, 1, $effectiveRate, 3, 'trim', $products);

        // Recalculate estimate totals from the surfaces we just created
        $estimate->refresh();
        $estimate->calculateTotals();
        $estimate->calculateDepositAndBalance();

        // ===== CREATE JOB (required for crew bonus display) =====
        $job = Job::create([
            'deal_id' => $deal->id,
            'estimate_id' => $estimate->id,
            'job_number' => 'JOB-SAMPLE-001',
            'stage' => 'in_progress',
            'title' => $deal->title,
            'description' => $estimate->description,
            'required_crew_size' => 2,
            'scheduled_date' => now()->addDays(3),
        ]);

        // Create a sample crew user with 2% bonus
        $crewUser = User::firstOrCreate(
            ['email' => 'sample-crew@system.local'],
            [
                'name' => 'Mike Rodriguez',
                'type' => 'crew',
                'password' => bcrypt('not-a-real-password'),
            ]
        );

        CrewProfile::updateOrCreate(
            ['user_id' => $crewUser->id],
            [
                'company_name' => 'Rodriguez Painting LLC',
                'phone' => '(303) 555-0200',
                'crew_size' => 2,
                'specialties' => ['interior', 'exterior'],
                'hourly_rate' => 35.00,
                'bonus_percent' => 2.00,
                'bio' => '10+ years of residential painting experience in the Denver metro area.',
            ]
        );

        // Attach crew as accepted on the job
        DB::table('job_crew')->insert([
            'job_id' => $job->id,
            'crew_user_id' => $crewUser->id,
            'status' => 'accepted',
            'dispatched_at' => now()->subDays(5),
            'responded_at' => now()->subDays(4),
            'notes' => 'Ready to go!',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ===== PRE-POPULATE FINANCIALS =====
        $estimate->actual_painters = [
            ['name' => 'Mike Rodriguez', 'hourly_rate' => 27.00, 'hours' => 8, 'notes' => 'Lead painter - walls & ceilings'],
            ['name' => 'Carlos Mendez', 'hourly_rate' => 23.00, 'hours' => 8, 'notes' => 'Trim, baseboards & doors'],
        ];

        $estimate->actual_paint_costs = self::buildActualPaintCosts($products);
        $estimate->save();

        return $estimate->load([
            'areas.surfaces.productionRate',
            'areas.surfaces.paintProduct.brand',
            'areas.addons.nestedAreaTemplate',
            'areas.substrate',
            'deal.property',
            'deal.customer.user',
            'job.crews.crewProfile',
        ]);
    }

    /**
     * Create a surface with pre-computed values.
     * Always sets paint_brand/paint_type (legacy fallback) AND paint_product_id (when available).
     * This ensures the materialsWithCosts computed finds the data via either path.
     */
    private static function createSurface(
        EstimateArea $area,
        string $name,
        string $unit,
        float $quantity,
        float $productionRate,
        int $coats,
        float $hourlyRate,
        int $sortOrder,
        string $paintKey,
        array $products,
    ): EstimateAreaSurface {
        $paint = self::PAINT_DATA[$paintKey];
        $product = $products[$paintKey] ?? null;

        // coat multiplier: 1 coat = 1.0, 2 coats = 1.8
        $coatMultiplier = $coats === 1 ? 1.0 : 1.8;

        $laborHours = round(($quantity / $productionRate) * $coatMultiplier, 2);
        $laborCost = round($laborHours * $hourlyRate, 2);

        // Paint gallons: sqft surfaces use ~400 sqft/gal coverage, lnft ~200 lnft/gal
        $paintGallons = match ($unit) {
            'sqft' => ceil(($quantity * $coatMultiplier) / 400),
            'lnft' => ceil(($quantity * $coatMultiplier) / 200),
            'unit' => ceil($quantity * $coatMultiplier * 0.25), // ~0.25 gal per door per coat
            default => 0,
        };

        // Use PaintProduct price if available, otherwise hardcoded price
        $pricePerGallon = $product ? (float) $product->price_per_gallon : $paint['price'];
        $paintCost = round($paintGallons * $pricePerGallon, 2);
        $materialCost = $paintCost;
        $total = round($laborCost + $materialCost, 2);

        return EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => $name,
            'surface_type' => $unit,
            'quantity' => $quantity,
            'unit' => $unit,
            'coats' => $coats,
            'application_method' => 'brush_roll',
            'paint_product_id' => $product?->id,
            'paint_brand' => $paint['brand'],
            'paint_type' => $paint['type'],
            'production_rate_value' => $productionRate,
            'hourly_labor_rate' => $hourlyRate,
            'material_cost_per_unit' => 0,
            'labor_hours' => $laborHours,
            'labor_cost' => $laborCost,
            'material_cost' => $materialCost,
            'paint_gallons' => $paintGallons,
            'paint_cost' => $paintCost,
            'total' => $total,
            'is_included' => true,
            'is_custom' => false,
            'sort_order' => $sortOrder,
        ]);
    }
}

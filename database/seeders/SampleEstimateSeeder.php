<?php

namespace Database\Seeders;

use App\Models\CrewProfile;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\Job;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SampleEstimateSeeder extends Seeder
{
    public const ESTIMATE_NUMBER = 'SAMPLE-ONBOARDING-001';

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
            return $existing;
        }

        return self::createSampleEstimate();
    }

    public function run(): void
    {
        self::ensureExists();
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

        // Effective rate: $25/hr base × 1.35 burden = $33.75/hr
        $effectiveRate = 33.75;

        // ===== LIVING ROOM (15×12×9) =====
        $livingRoom = EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Living Room',
            'scope_type' => 'interior',
            'length' => 15,
            'width' => 12,
            'height' => 9,
            'wall_sqft' => 486,    // 2×(15+12)×9
            'ceiling_sqft' => 180, // 15×12
            'perimeter_lnft' => 54,
            'baseboard_lnft' => 54,
            'sort_order' => 1,
        ]);

        // Walls: 486 sqft, 200 sqft/hr, 2 coats → ~4.37 hrs, ~2.43 gal
        self::createSurface($livingRoom, 'Walls', 'sqft', 486, 200, 2, $effectiveRate, 'sherwin_williams', 'duration', 1);
        // Ceiling: 180 sqft, 250 sqft/hr, 1 coat → 0.72 hrs, ~0.45 gal
        self::createSurface($livingRoom, 'Ceiling', 'sqft', 180, 250, 1, $effectiveRate, 'sherwin_williams', 'duration', 2);
        // Baseboards: 54 lnft, 60 lnft/hr, 1 coat → 0.90 hrs
        self::createSurface($livingRoom, 'Baseboards', 'lnft', 54, 60, 1, $effectiveRate, 'sherwin_williams', 'duration', 3);
        // Door: 1 unit, 2 units/hr, 2 coats → 0.90 hrs
        self::createSurface($livingRoom, 'Door', 'unit', 1, 2, 2, $effectiveRate, 'sherwin_williams', 'duration', 4);

        // ===== KITCHEN (12×10×9) =====
        $kitchen = EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Kitchen',
            'scope_type' => 'interior',
            'length' => 12,
            'width' => 10,
            'height' => 9,
            'wall_sqft' => 396,    // 2×(12+10)×9
            'ceiling_sqft' => 120, // 12×10
            'perimeter_lnft' => 44,
            'baseboard_lnft' => 44,
            'sort_order' => 2,
        ]);

        self::createSurface($kitchen, 'Walls', 'sqft', 396, 200, 2, $effectiveRate, 'sherwin_williams', 'duration', 1);
        self::createSurface($kitchen, 'Ceiling', 'sqft', 120, 250, 1, $effectiveRate, 'sherwin_williams', 'duration', 2);
        self::createSurface($kitchen, 'Baseboards', 'lnft', 44, 60, 1, $effectiveRate, 'sherwin_williams', 'duration', 3);

        // ===== MASTER BEDROOM (14×13×9) =====
        $masterBedroom = EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Master Bedroom',
            'scope_type' => 'interior',
            'length' => 14,
            'width' => 13,
            'height' => 9,
            'wall_sqft' => 486,    // 2×(14+13)×9
            'ceiling_sqft' => 182, // 14×13
            'perimeter_lnft' => 54,
            'baseboard_lnft' => 54,
            'sort_order' => 3,
        ]);

        self::createSurface($masterBedroom, 'Walls', 'sqft', 486, 200, 2, $effectiveRate, 'sherwin_williams', 'duration', 1);
        self::createSurface($masterBedroom, 'Ceiling', 'sqft', 182, 250, 1, $effectiveRate, 'sherwin_williams', 'duration', 2);
        self::createSurface($masterBedroom, 'Baseboards', 'lnft', 54, 60, 1, $effectiveRate, 'sherwin_williams', 'duration', 3);
        self::createSurface($masterBedroom, 'Doors', 'unit', 2, 2, 2, $effectiveRate, 'sherwin_williams', 'duration', 4);

        // ===== BATHROOM (8×6×9) =====
        $bathroom = EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => 'Bathroom',
            'scope_type' => 'interior',
            'length' => 8,
            'width' => 6,
            'height' => 9,
            'wall_sqft' => 252,   // 2×(8+6)×9
            'ceiling_sqft' => 48, // 8×6
            'perimeter_lnft' => 28,
            'baseboard_lnft' => 28,
            'sort_order' => 4,
        ]);

        self::createSurface($bathroom, 'Walls', 'sqft', 252, 200, 2, $effectiveRate, 'sherwin_williams', 'duration', 1);
        self::createSurface($bathroom, 'Ceiling', 'sqft', 48, 250, 1, $effectiveRate, 'sherwin_williams', 'duration', 2);
        self::createSurface($bathroom, 'Baseboards', 'lnft', 28, 60, 1, $effectiveRate, 'sherwin_williams', 'duration', 3);

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
        // Total labor hours from surfaces: ~25.2 hrs
        // Split between 2 painters (crew of 2)
        $totalLaborHours = (float) $estimate->areas->sum(fn ($area) => $area->surfaces->sum('labor_hours'));
        $painter1Hours = round($totalLaborHours * 0.55, 1); // Lead painter takes slightly more
        $painter2Hours = round($totalLaborHours * 0.45, 1);

        $estimate->actual_painters = [
            [
                'name' => 'Mike Rodriguez',
                'hourly_rate' => 35.00,
                'hours' => $painter1Hours,
                'notes' => 'Lead painter - walls & ceilings',
            ],
            [
                'name' => 'Carlos Mendez',
                'hourly_rate' => 35.00,
                'hours' => $painter2Hours,
                'notes' => 'Trim, baseboards & doors',
            ],
        ];

        // Actual paint costs: crew discount on SW Duration ($45/gal vs $65 catalog)
        // The materialsNeeded computed groups by legacy key "sherwin_williams_duration"
        $estimate->actual_paint_costs = [
            'sherwin_williams_duration' => 45.00,
        ];

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
     * Sets labor_hours, labor_cost, material_cost, and total directly
     * so the saving() hook on EstimateAreaSurface skips auto-calculation
     * (the guard at line 91 checks if these values are already set).
     */
    private static function createSurface(
        EstimateArea $area,
        string $name,
        string $unit,
        float $quantity,
        float $productionRate,
        int $coats,
        float $hourlyRate,
        string $paintBrand,
        string $paintType,
        int $sortOrder = 0,
    ): EstimateAreaSurface {
        // coat multiplier: 1 coat = 1.0, 2 coats = 1.8
        $coatMultiplier = $coats === 1 ? 1.0 : 1.8;

        $laborHours = round(($quantity / $productionRate) * $coatMultiplier, 2);
        $laborCost = round($laborHours * $hourlyRate, 2);

        // Paint gallons: sqft surfaces use ~400 sqft/gal coverage, lnft ~200 lnft/gal
        // These match Sherwin-Williams Duration typical coverage rates
        $paintGallons = match ($unit) {
            'sqft' => ceil(($quantity * $coatMultiplier) / 400),
            'lnft' => ceil(($quantity * $coatMultiplier) / 200),
            'unit' => ceil($quantity * $coatMultiplier * 0.25), // ~0.25 gal per door per coat
            default => 0,
        };

        // Paint cost: Sherwin-Williams Duration ~$65/gal retail
        $pricePerGallon = 65.00;
        $paintCost = round($paintGallons * $pricePerGallon, 2);

        // Material cost includes paint + sundries allocation
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
            'paint_brand' => $paintBrand,
            'paint_type' => $paintType,
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

<?php

namespace Database\Seeders;

use App\Models\Service;
use Illuminate\Database\Seeder;

class ServiceSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $sortOrder = 0;

        foreach ($this->services() as $slug => $data) {
            $sortOrder++;
            Service::updateOrCreate(
                ['slug' => $slug],
                array_merge($data, ['sort_order' => $sortOrder]),
            );
        }
    }

    private function services(): array
    {
        return [
            // ============================================================
            // RESIDENTIAL SERVICES (1-9)
            // ============================================================
            'interior-painting' => [
                'title' => 'Interior House Painting',
                'category' => 'Residential',
                'tagline' => 'Transform any room with a professional interior paint job that lasts',
                'meta_description' => 'Professional interior house painting in Denver. Expert wall, ceiling, and room painting with premium paints, proper prep, and a clean finish. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Interior painting is one of the most impactful home improvements you can make. A fresh coat of paint transforms tired, marked-up walls into clean, vibrant spaces that feel brand new. In Denver, where the dry air at 5,280 feet creates unique challenges for paint application and drying, professional technique makes a real difference in the final result.

The key to a great interior paint job is preparation. Professional painters spend 60-80% of their total job time on prep -- cleaning, repairing, sanding, priming, and masking -- before a single stroke of topcoat goes on the wall. This investment in preparation is what separates a paint job that lasts 7-10 years from one that shows wear in 2-3.

Denver homes face specific challenges: extremely dry air that causes paint to flash-dry and show lap marks, intense UV through south-facing windows that fades colors, and seasonal humidity swings that cause framing movement and wall cracks. Understanding and managing these conditions is essential for a professional result.',
                'materials' => [
                    'Paint and Primers' => [
                        'Interior latex paint (premium 100% acrylic -- Sherwin-Williams Duration, Benjamin Moore Regal Select, or Behr Marquee)',
                        'Primer (Zinsser BIN shellac-based for stain blocking, KILZ 2 latex for general priming)',
                        'Paint conditioner/extender (Floetrol) to slow drying in dry climates',
                    ],
                    'Application Tools' => [
                        '9-inch roller frames and covers (3/8" nap smooth, 1/2" nap textured)',
                        'Roller extension pole (4-8 ft adjustable)',
                        '2.5-inch angled sash brush (Purdy or Wooster -- synthetic bristle for latex)',
                        '5-gallon bucket with roller screen (preferred over paint trays for larger jobs)',
                    ],
                    'Preparation Supplies' => [
                        'Painter\'s tape (FrogTape Multi-Surface or 3M ScotchBlue)',
                        'Canvas drop cloths (minimum 9x12 ft)',
                        'TSP or TSP substitute for wall cleaning',
                        'Lightweight spackle, putty knives, sandpaper (120-220 grit)',
                        'Paintable acrylic latex caulk and caulk gun',
                    ],
                    'Safety' => [
                        'Step ladder (6 ft) and extension ladder for high ceilings',
                        'Safety glasses and N95 dust masks for sanding',
                        'Nitrile gloves and work light for final inspection',
                    ],
                ],
                'steps' => [
                    ['title' => 'Room Assessment and Planning', 'description' => 'Walk through each room to assess wall and ceiling conditions. Note cracks, nail holes, water stains, peeling paint, or mildew. Measure square footage to calculate paint quantities -- a gallon covers approximately 350-400 square feet per coat.'],
                    ['title' => 'Color Selection and Paint Purchase', 'description' => 'Select colors using large paint swatches taped directly to walls. Purchase premium 100% acrylic latex paint. For Denver homes, choose low-VOC formulations that perform better in low-humidity environments. Buy 10-15% more than calculations suggest for touch-ups.'],
                    ['title' => 'Room Preparation and Furniture Protection', 'description' => 'Remove all furniture or consolidate in the center and cover with plastic sheeting. Remove outlet covers, switch plates, light fixtures, and wall art. Cover floors with canvas drop cloths -- they absorb drips and stay in place better than plastic.'],
                    ['title' => 'Surface Cleaning', 'description' => 'Wash walls with TSP solution to remove grease, dust, and grime. Pay special attention to kitchen and bathroom walls. Rinse and allow to dry completely -- in Denver\'s dry air, this takes 2-4 hours rather than the 6-8 typical at lower elevations.'],
                    ['title' => 'Surface Repair', 'description' => 'Fill nail holes and cracks with lightweight spackle. For larger cracks, use mesh tape and joint compound. Scrape peeling paint, sand repairs smooth with 150-grit sandpaper, and caulk gaps between trim and walls with paintable caulk.'],
                    ['title' => 'Sanding', 'description' => 'Lightly sand all surfaces with 120-150 grit sandpaper to create mechanical tooth for paint adhesion. This deglossing step is critical over previously painted surfaces. Vacuum dust and wipe with a damp microfiber cloth.'],
                    ['title' => 'Masking and Taping', 'description' => 'Apply painter\'s tape along ceiling lines, trim, baseboards, window frames, and door frames. Press tape edges firmly with a putty knife to prevent paint bleed. Cover floors along baseboards with tape-attached masking film.'],
                    ['title' => 'Priming', 'description' => 'Apply primer to repaired areas, bare drywall, stained surfaces, and dramatic color changes. Use stain-blocking primer over water stains. In Denver\'s dry air, primer is non-negotiable -- it creates a moisture barrier that prevents topcoat adhesion failure.'],
                    ['title' => 'Cut In Edges (First Coat)', 'description' => 'Using a 2.5-inch angled sash brush, cut in around all edges -- ceiling lines, corners, trim, outlets. Work in 3-4 foot sections and immediately roll while the cut-in area is still wet to blend brush and roller marks.'],
                    ['title' => 'Roll Walls (First Coat)', 'description' => 'Apply paint in a W or M pattern with a roller, then fill in with parallel strokes. Work in 3x3 foot sections, maintaining a wet edge. Roll from floor to ceiling in smooth, overlapping strokes.'],
                    ['title' => 'Paint Ceilings', 'description' => 'Use flat or matte ceiling paint. Cut in edges first, then roll in one direction perpendicular to the primary light source to minimize visible lap marks. Use an extension pole for consistent pressure.'],
                    ['title' => 'Second Coat Application', 'description' => 'Allow first coat to dry fully (2-4 hours in Denver). Lightly sand any drips with 220-grit. Apply the second coat -- two coats is standard, and dramatic color changes may require a third coat.'],
                    ['title' => 'Trim and Detail Work', 'description' => 'Paint trim after walls using semi-gloss or satin finish for durability and cleanability. Use a high-quality angled brush and maintain steady pressure for clean lines.'],
                    ['title' => 'Final Inspection and Touch-ups', 'description' => 'Remove painter\'s tape at a 45-degree angle while the final coat is still slightly tacky. Inspect all surfaces under strong lighting at multiple angles. Touch up missed spots and reinstall fixtures.'],
                    ['title' => 'Cleanup and Curing', 'description' => 'Clean tools immediately. Store leftover paint for touch-ups. Allow 24-48 hours before light use, 30 days for full cure. In Denver, initial drying is fast but full chemical cure still requires 2-4 weeks.'],
                ],
                'tips' => [
                    ['title' => 'Use Floetrol paint conditioner', 'description' => 'Denver\'s 30-40% humidity causes paint to dry 2-3x faster than at sea level. Add Floetrol to your latex paint (8 oz per gallon) to extend open time, prevent lap marks, and allow paint to self-level.'],
                    ['title' => 'Prime everything, even over "paint and primer in one"', 'description' => 'Denver\'s dry air causes surfaces to absorb moisture unevenly. Bare drywall and patches will flash without dedicated primer. The dry air exacerbates differential absorption.'],
                    ['title' => 'Paint when humidity is highest', 'description' => 'Denver\'s most humid months are July-August during monsoon season. Run a humidifier set to 40-50% RH in the room during painting. Turn off forced-air HVAC vents to prevent flash drying.'],
                    ['title' => 'Use synthetic brushes only', 'description' => 'Natural-bristle brushes absorb moisture from air to maintain shape. In Denver\'s dry air, they become frizzy and uncontrollable. Synthetic filament brushes like Purdy XL maintain their shape regardless of humidity.'],
                    ['title' => 'Do not rush recoat times', 'description' => 'Paint may feel dry to touch in 30-60 minutes in Denver, but this is surface drying only. Recoating too soon traps solvents and can cause adhesion failure or bubbling. Wait the full recommended time.'],
                    ['title' => 'Account for Colorado sun through windows', 'description' => 'South and west-facing rooms receive intense UV. Use paint with UV inhibitors for these rooms. Darker accent walls facing windows are particularly vulnerable to fading.'],
                ],
                'cost_factors' => [
                    'Room size and quantity -- square footage is the primary driver',
                    'Ceiling height -- vaulted or cathedral ceilings dramatically affect ladder work and time',
                    'Wall condition -- extensive patching and crack repair adds significant prep time',
                    'Paint quality -- builder-grade ($20-30/gallon) vs. premium ($50-80/gallon)',
                    'Number of colors -- each color change requires separate cutting-in and cleanup',
                    'Color change severity -- dark to light may require tinted primer and 3+ coats',
                    'Architectural complexity -- crown molding, wainscoting, and built-in shelving increase time',
                    'Access difficulty -- stairwells and multi-story foyers require scaffolding',
                ],
                'faqs' => [
                    ['q' => 'How long does interior paint last before it needs to be redone?', 'a' => 'In Denver homes, interior paint typically lasts 5-10 years depending on room traffic. High-traffic areas like hallways and kitchens may show wear in 3-5 years, while bedrooms can last 7-10 years. The dry air in Colorado actually helps longevity since there is less moisture-related peeling. Using premium paint extends the timeline significantly.'],
                    ['q' => 'What is the best paint finish for different rooms?', 'a' => 'Flat/matte is best for ceilings and low-traffic rooms. Eggshell is the most popular for living rooms, dining rooms, and bedrooms. Satin is ideal for kitchens, bathrooms, and hallways because it resists moisture and cleans easily. Semi-gloss is reserved for trim, doors, and cabinets for durability.'],
                    ['q' => 'Do I really need two coats of paint?', 'a' => 'Yes, in almost all cases. The first coat provides coverage and adhesion; the second provides color depth and durability. A single coat may look acceptable when wet but will show thin spots when dry. In Denver, where dry air causes uneven absorption, two coats are especially important.'],
                    ['q' => 'How long should I wait before hanging pictures or cleaning walls?', 'a' => 'You can carefully touch walls after 2-4 hours. Hang lightweight items after 48-72 hours. Full cure takes 30 days -- avoid scrubbing or cleaning chemicals during this period. In Denver, initial drying is faster, but full chemical cure still requires the standard timeframe.'],
                    ['q' => 'Is it better to paint in summer or winter in Colorado?', 'a' => 'Any season works for interior painting. Winter means extremely dry indoor air (10-20% RH), requiring Floetrol and a humidifier. Summer monsoon season (July-August) provides naturally higher humidity (35-50% RH) that helps paint flow and level. The key is managing humidity to 40-50% RH regardless of season.'],
                ],
            ],

            'exterior-painting' => [
                'title' => 'Exterior House Painting',
                'category' => 'Residential',
                'tagline' => 'Protect and transform your home\'s exterior with paint built for Denver\'s climate',
                'meta_description' => 'Professional exterior house painting in Denver. UV-resistant paint, expert prep, and climate-aware application for lasting curb appeal. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Exterior painting in Denver is a different challenge than anywhere else in the country. At 5,280 feet, your home endures 25% more UV radiation than sea-level cities, 40-degree daily temperature swings, 100+ freeze-thaw cycles per year, and 7-9 hail events annually. These conditions mean exterior paint in Denver lasts 5-8 years, not the 10-15 advertised on the can.

The right paint, proper surface preparation, and climate-aware application techniques make the difference between a paint job that protects your home for 8+ years and one that starts peeling in 3. Professional exterior painters follow the shade around your house, use premium UV-resistant acrylic latex formulations, and time every coat to ensure proper curing before nighttime temperature drops.

South-facing and west-facing walls take the most punishment and may need attention 2-3 years before other sides. Understanding this differential degradation is key to maintaining your home\'s curb appeal and structural protection.',
                'materials' => [
                    'Paint and Primers' => [
                        'Exterior acrylic latex paint (Sherwin-Williams Duration, Benjamin Moore Aura Exterior, or Behr Marquee Exterior)',
                        'Exterior primer (Zinsser Bulls Eye 1-2-3 or Sherwin-Williams Exterior Bonding Primer)',
                        'Specialty primers for metal and masonry as needed',
                    ],
                    'Application Tools' => [
                        'Airless paint sprayer with appropriate tips',
                        '9-inch roller frames with 3/4" or 1" nap covers for textured siding',
                        '2.5-inch and 3-inch angled sash brushes (synthetic)',
                        'Extension poles (6-12 ft) and 5-gallon buckets with roller screens',
                    ],
                    'Preparation Tools' => [
                        'Pressure washer (2,000-3,000 PSI with multiple tips)',
                        'Carbide scrapers and 5-in-1 tools for peeling paint removal',
                        'Random orbital sander with 60, 80, and 120-grit discs',
                        'Caulk gun and exterior-grade caulk, wood filler or epoxy consolidant',
                    ],
                    'Safety Equipment' => [
                        'Extension ladders (24-32 ft), ladder stabilizer',
                        'Scaffolding for large areas or multi-story homes',
                        'Fall protection harness for work above 6 ft',
                        'Safety glasses, N95 respirators, and work gloves',
                    ],
                ],
                'steps' => [
                    ['title' => 'Exterior Inspection', 'description' => 'Walk the entire perimeter inspecting all paintable surfaces for peeling, cracking, chalking, mildew, wood rot, and caulk failure. Note south and west-facing walls, which degrade 30-40% faster in Denver. Document everything with photos.'],
                    ['title' => 'Surface Preparation -- Washing', 'description' => 'Pressure wash the entire exterior at 1,500-2,500 PSI. Apply mildew-killing solution for biological growth. Allow 24-48 hours to dry completely. In Denver, verify with a moisture meter that wood reads below 15% moisture content.'],
                    ['title' => 'Scraping and Sanding', 'description' => 'Scrape all loose, peeling paint using a carbide scraper. Feather edges of remaining paint with 80-grit sandpaper. For pre-1978 homes, test for lead paint first and follow EPA RRP rules if positive.'],
                    ['title' => 'Repairs -- Wood, Caulk, and Masonry', 'description' => 'Replace rotted wood with epoxy filler. Dig out cracked caulk and re-caulk all joints with high-quality siliconized acrylic caulk. Repair cracked stucco with patching compound. Reset popped nails.'],
                    ['title' => 'Priming', 'description' => 'Prime all bare wood, repaired areas, and surfaces with significant chalking. Use primer with UV-blocking properties. Tint primer toward the topcoat color for better coverage.'],
                    ['title' => 'Masking and Protection', 'description' => 'Cover windows, doors, light fixtures, and landscaping. Tape off stone, brick accents, and surfaces not being painted. Protect HVAC units and electrical panels.'],
                    ['title' => 'Cut In (First Coat)', 'description' => 'Cut in around all edges using a 2.5-inch angled brush. Work from top to bottom. In Denver, follow the shade around the house -- never paint surfaces in direct afternoon sun.'],
                    ['title' => 'Apply First Topcoat', 'description' => 'Apply premium 100% acrylic latex using airless sprayer, roller, or brush. Always back-brush or back-roll to work paint into surface texture. Maintain a wet edge and apply at recommended film thickness.'],
                    ['title' => 'Apply Second Topcoat', 'description' => 'Allow first coat to dry 4-6 hours per manufacturer specs. Lightly sand drips. Apply second coat -- two coats is the professional standard. On south-facing walls, some Denver pros apply a third coat for added UV protection.'],
                    ['title' => 'Trim, Fascia, and Detail Painting', 'description' => 'Paint trim, fascia, soffits, window frames, door frames, and shutters with contrasting color in semi-gloss or satin finish. These require precision brush work.'],
                    ['title' => 'Final Inspection and Cleanup', 'description' => 'Walk the entire perimeter with the client, checking for missed spots, drips, and overspray. Touch up issues. Remove all masking, drop cloths, and debris.'],
                ],
                'tips' => [
                    ['title' => 'Follow the shade', 'description' => 'Denver\'s daily temperature swings are brutal: 75 degrees F afternoon can drop to 35 degrees F overnight. Start painting west and south sides in the morning while in shade, move to east and north in the afternoon.'],
                    ['title' => 'Use temperature-flexible paint', 'description' => 'Denver\'s 40-degree daily swings cause siding to expand and contract. Use 100% acrylic latex with high elongation properties. Avoid oil-based exterior paints -- they become brittle and crack in temperature cycling.'],
                    ['title' => 'Watch for hail damage', 'description' => 'Denver averages 7-9 hail events per year, May through August. Schedule exterior painting for late August through early October -- after hail season, before cold weather.'],
                    ['title' => 'Prep for extreme dryness on wood siding', 'description' => 'Colorado\'s 30-40% humidity causes wood to dry out, shrink, and crack. Seal all exposed end grain. Apply two coats of primer to bare wood. Back-prime new replacement boards on all six sides.'],
                    ['title' => 'Chalking is accelerated at altitude', 'description' => 'UV breaks down paint binder 30-40% faster at altitude. Always test for chalk before painting and prime with a chalk-binding primer if detected. Painting over chalk causes immediate adhesion failure.'],
                ],
                'cost_factors' => [
                    'Home size and stories -- total paintable surface; multi-story homes require more setup time',
                    'Siding type -- smooth siding is faster than textured stucco or shake',
                    'Surface condition -- extensive scraping and wood rot repair increases prep labor',
                    'Paint quality -- premium UV-resistant paints ($50-80/gallon) last longer in Denver',
                    'Number of colors -- body, trim, and accent each require separate passes',
                    'Accessibility -- steep rooflines and landscaping obstructions require special equipment',
                    'Lead paint -- pre-1978 homes require lead-safe work practices, adding cost',
                ],
                'faqs' => [
                    ['q' => 'How often should I repaint my home\'s exterior in Denver?', 'a' => 'Most Denver homes need exterior repainting every 5-8 years, compared to 7-12 years at lower elevations. South and west-facing walls degrade fastest. Signs it is time: visible chalking, cracking or peeling, significant fading, and exposed bare wood. Premium UV-resistant paint can push the interval toward 8-10 years.'],
                    ['q' => 'What is the best exterior paint for Denver\'s climate?', 'a' => 'Premium 100% acrylic latex with UV protection and high elongation. Top choices: Sherwin-Williams Duration, Benjamin Moore Aura Exterior, and PPG Manor Hall. Avoid oil/alkyd-based paints -- they become brittle in temperature swings. For stucco, consider elastomeric coatings that bridge hairline cracks.'],
                    ['q' => 'Can I paint the exterior in winter in Colorado?', 'a' => 'It is possible but risky. Modern paints can be applied at 35 degrees F, but both surface and air temperature must stay above that for 24-48 hours of curing. Denver winter days of 55 degrees F often drop below 35 degrees F overnight. If you must, paint only south and west sides during the warmest part of the day.'],
                    ['q' => 'Should I use a sprayer, roller, or brush?', 'a' => 'Professional painters use all three: an airless sprayer for speed and coverage, followed by back-brushing or back-rolling to work paint into the grain. Spraying alone applies paint on top; back-brushing works it into crevices for superior adhesion.'],
                    ['q' => 'How do I handle lead paint on older Denver homes?', 'a' => 'Homes built before 1978 may contain lead paint. Federal law requires EPA-certified renovators for work disturbing more than 6 square feet of painted surface. This includes containment, HEPA-vacuuming, and proper disposal. Many established Denver neighborhoods have pre-1978 homes. Never dry-scrape suspected lead paint.'],
                ],
            ],

            'cabinet-painting' => [
                'title' => 'Cabinet Painting & Refinishing',
                'category' => 'Residential',
                'tagline' => 'Get a brand-new kitchen look at a fraction of the cost of new cabinets',
                'meta_description' => 'Professional cabinet painting and refinishing in Denver. Smooth, factory-quality finish on kitchen and bathroom cabinets. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Cabinet painting is one of the highest-impact, most cost-effective kitchen renovations. A professional paint job transforms dated, worn cabinets into fresh, modern surfaces for 40-60% less than replacement. The key is using the right products, proper preparation, and patience with cure times.

Professional cabinet painting uses hybrid alkyd/acrylic enamel paints like Benjamin Moore Advance or Sherwin-Williams Emerald Urethane Trim Enamel. These products level beautifully, minimizing brush marks, and cure to a hard, washable, furniture-quality finish. White, off-white, and light gray remain the most popular choices.

Denver\'s dry climate presents unique challenges for cabinet work. Shellac primer dries in 15-30 minutes instead of the typical 45 minutes at sea level. Hybrid alkyd paints skin over before self-leveling in low humidity. Temperature-controlled workspace with 40-50% relative humidity is critical for achieving the smooth, professional finish that separates professional results from DIY attempts.',
                'materials' => [
                    'Paint and Primers' => [
                        'Cabinet-grade paint (Benjamin Moore Advance, Sherwin-Williams Emerald Urethane Trim Enamel)',
                        'Shellac-based primer (Zinsser BIN) or bonding primer (KILZ Adhesion)',
                        'Heavy-duty degreaser (Krud Kutter or TSP)',
                    ],
                    'Application Tools' => [
                        '4-inch and 6-inch high-density foam rollers for flat surfaces',
                        '2-inch angled sash brush (high quality, synthetic) for detail work',
                        'Spray equipment (HVLP or airless) optional but preferred',
                        'Painter\'s pyramids for drying doors without sticking',
                    ],
                    'Preparation Tools' => [
                        '150-grit, 220-grit, and 320-grit sandpaper and sanding sponges',
                        'Scotch-Brite pads for degreasing scrub',
                        'Auto body filler or wood filler for dents and grain filling',
                        'Paintable caulk, tack cloths, and vacuum with brush attachment',
                    ],
                    'Organization' => [
                        'Painter\'s tape and marker for labeling doors (e.g., U1, L3)',
                        'Zip-lock bags for hardware storage',
                        'Sawhorses or drying racks, drop cloths',
                    ],
                ],
                'steps' => [
                    ['title' => 'Assessment and Planning', 'description' => 'Inspect all cabinet boxes, doors, and drawer fronts for grease, peeling finish, or water damage. Count all pieces and determine if face frames and interiors will be painted. Assess material type -- solid wood, MDF, thermofoil, or laminate each require different approaches.'],
                    ['title' => 'Color and Finish Selection', 'description' => 'Choose color and finish -- satin or semi-gloss is standard for cabinets. Use hybrid alkyd/acrylic enamel for a smooth, durable, furniture-quality finish that levels beautifully.'],
                    ['title' => 'Remove Doors, Drawers, and Hardware', 'description' => 'Remove all doors and drawer fronts. Number each door and opening with labeled tape (e.g., "U1"). Remove all hinges, pulls, and knobs. Store hardware in labeled bags. This labeling system is critical for reinstallation.'],
                    ['title' => 'Set Up Drying Area', 'description' => 'Set up sawhorses or drying racks in a well-ventilated space. Use painter\'s pyramids so doors can be set down wet without sticking. Plan for 3-5 days through the full process.'],
                    ['title' => 'Deep Cleaning and Degreasing', 'description' => 'Clean all surfaces with heavy-duty degreaser, especially above the stove. Scrub with Scotch-Brite pad, rinse, and dry completely. Grease contamination is the number one cause of cabinet paint failure.'],
                    ['title' => 'Fill and Repair', 'description' => 'Fill dents, holes, and grain with auto body filler or wood filler. Caulk panel-to-frame joints and fill old hardware holes if changing locations. Let filler dry completely.'],
                    ['title' => 'Sanding', 'description' => 'Sand all surfaces with 150-grit to remove existing gloss and create adhesion tooth. Use sanding sponges for profile edges and panel details. Vacuum and tack-cloth all dust.'],
                    ['title' => 'Apply Primer', 'description' => 'Apply high-adhesion primer. Zinsser BIN shellac-based is the gold standard for blocking tannin bleed that causes yellowing through white paint. In Denver\'s dry air, shellac primer dries in 15-30 minutes -- work quickly in small sections.'],
                    ['title' => 'Sand Primer', 'description' => 'Sand primer lightly with 220-grit to knock down raised grain and roller texture. This sanding step between primer and paint separates professional results from amateur results.'],
                    ['title' => 'Apply First Coat of Finish Paint', 'description' => 'Use foam roller for flat surfaces, brush for edges and panel details. Paint backs of doors first. Apply thin coats -- thick coats run, sag, and take forever to cure.'],
                    ['title' => 'Sand Between Coats', 'description' => 'Allow first coat to dry per manufacturer directions (16-24 hours for hybrid alkyd). Lightly sand with 220 or 320-grit. Remove dust with tack cloth.'],
                    ['title' => 'Apply Second and Third Coats', 'description' => 'Apply second coat using same technique. For high-use kitchen areas, a third coat provides additional durability. Two coats minimum; three coats is best practice.'],
                    ['title' => 'Cure Time', 'description' => 'Allow cabinets to cure 5-7 days minimum before reinstallation. Hybrid alkyd paints reach full hardness in 7-14 days. Do not stack doors during this period -- even dry-feeling paint can block under pressure.'],
                    ['title' => 'Reinstall Hardware and Doors', 'description' => 'Install new or cleaned hardware. Rehang doors using the numbering system. Adjust hinges for proper alignment. Soft-close hinges are a popular upgrade during refinishing.'],
                    ['title' => 'Final Inspection', 'description' => 'Inspect under strong lighting and touch up imperfections. Instruct homeowner: do not scrub or use harsh cleaners for 30 days while paint cures. Use only a damp microfiber cloth during the first month.'],
                ],
                'tips' => [
                    ['title' => 'Cure time is the hidden enemy in Denver', 'description' => 'Hybrid alkyd paints cure through oxidation, not evaporation. Denver\'s dry air accelerates surface drying but can slow the oxidation underneath. Do not reinstall doors before 7 days minimum.'],
                    ['title' => 'Temperature-controlled workspace is critical', 'description' => 'Run a humidifier set to 45% RH. In winter, Denver homes can drop to 10-15% RH -- painting cabinets in those conditions without humidity control produces inferior results with orange-peel texture.'],
                    ['title' => 'Shellac primer dries in minutes', 'description' => 'BIN shellac primer dries in 15-30 minutes in Denver versus 45 minutes at sea level. Pour small quantities into a paint pot rather than working from the can.'],
                    ['title' => 'Control dust aggressively', 'description' => 'Denver\'s dry air generates significant static electricity, attracting dust to wet surfaces. Use a tack cloth before every coat and seal the painting area as completely as possible.'],
                ],
                'cost_factors' => [
                    'Number of cabinets -- total doors, drawer fronts, and boxes drives labor hours',
                    'Cabinet construction -- solid wood is easiest; thermofoil and laminate require specialized primers',
                    'Door style -- flat-panel (Shaker) is faster than raised-panel with complex profiles',
                    'Interior painting -- painting inside cabinet boxes adds 40-60% more labor',
                    'Application method -- professional spray finish costs more but produces smoothest results',
                    'Hardware replacement -- new hinges and pulls add material costs',
                ],
                'faqs' => [
                    ['q' => 'How long does a professional cabinet paint job last?', 'a' => 'With proper prep and professional application, painted cabinets last 8-15 years. The key factors are preparation quality and paint type -- hybrid alkyd enamels outlast standard latex by years. In Denver, kitchen cabinets near stoves may show wear faster due to heat and grease.'],
                    ['q' => 'Can you paint over laminate or thermofoil cabinets?', 'a' => 'Yes, with specialized bonding primer (KILZ Adhesion, Stix, or XIM 400) that chemically bonds to slick surfaces. Sand lightly with 220-grit first. The results can be excellent, but these surfaces are less forgiving and more prone to chipping at edges.'],
                    ['q' => 'Should I paint or refinish (stain) my cabinets?', 'a' => 'Painting covers grain completely, works on any material, and provides a clean modern look. Refinishing preserves natural wood grain but only works on solid wood. Painting costs 40-60% less than full refinishing. If you have beautiful solid wood, refinishing preserves their natural value.'],
                    ['q' => 'How long does the cabinet painting process take?', 'a' => 'A full kitchen takes 5-10 working days. Day 1: removal and cleaning. Days 2-3: sanding, filling, priming. Days 4-6: painting with sanding between coats. Days 7-10: curing and reinstallation. Your kitchen is partially usable during the process.'],
                ],
            ],

            'deck-fence-staining' => [
                'title' => 'Deck & Fence Staining',
                'category' => 'Residential',
                'tagline' => 'Protect and preserve your wood with stains built for Colorado\'s extreme conditions',
                'meta_description' => 'Professional deck and fence staining in Denver. UV-protective penetrating stains, proper wood prep, and application timed for Colorado\'s climate. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Wood decks and fences in Colorado face extreme conditions: 25% more UV radiation than sea level, 40-degree daily temperature swings, 100+ freeze-thaw cycles, and intense summer sun that can heat wood surfaces to 130-150 degrees F. Without proper protection, exposed wood grays, cracks, and deteriorates rapidly.

The most important decision for Colorado wood protection is choosing a penetrating oil-based stain over a film-forming product. Film-forming stains sit on the surface, crack, and peel as wood expands and contracts through Denver\'s temperature cycling. Penetrating stains soak into the wood fibers and flex with expansion and contraction without cracking. When they wear, they fade gradually and can be recoated without stripping.

Plan to restain deck floors every 2-3 years and fence panels every 3-4 years. Clear sealers provide almost no UV protection and last under a year in Denver -- they are effectively a waste of money in the Colorado sun. Semi-transparent stains provide the best balance of UV protection and wood grain visibility.',
                'materials' => [
                    'Stain Products' => [
                        'Exterior wood stain (TWP, Ready Seal, Cabot, Armstrong-Clark, or Benjamin Moore Arborcoat)',
                        'Deck/fence cleaner (oxalic acid-based: Restore-A-Deck, DeckWise)',
                        'Wood brightener (oxalic acid based) for restoring natural color',
                        'Chemical stripper (for removing old failed finish if necessary)',
                    ],
                    'Application Tools' => [
                        'Stain pads and pad applicators for deck floors',
                        'Natural-bristle stain brushes (4-inch and 6-inch)',
                        'Paint rollers with 3/8" nap covers for fence panels',
                        'Pump sprayer for cleaner and stain application with back-brushing',
                    ],
                    'Preparation Tools' => [
                        'Pressure washer (1,500-2,500 PSI)',
                        'Pin-type moisture meter (wood must be below 15%)',
                        '60-80 grit sandpaper for splinters and rough spots',
                        'Nail set, hammer, drill for tightening loose boards',
                    ],
                    'Safety and Protection' => [
                        'Chemical-resistant gloves and safety glasses',
                        'N95 respirator when spraying',
                        'Drop cloths and plastic sheeting for adjacent surfaces',
                    ],
                ],
                'steps' => [
                    ['title' => 'Wood Assessment', 'description' => 'Inspect all wood for rot, splintering, mildew, and graying. Check for loose nails, soft spots indicating rot. Perform a water test: if water beads up, existing finish must be stripped. If it absorbs within 10 minutes, wood is ready for stain.'],
                    ['title' => 'Wood Repairs', 'description' => 'Replace rotted boards, set popped nails below surface, tighten loose screws. Sand splinters and rough spots with 60-80 grit. Replace severely split or warped boards. For fences, reattach loose pickets.'],
                    ['title' => 'Chemical Cleaning', 'description' => 'Apply deck/fence cleaner with a pump sprayer. For mildew, use sodium percarbonate cleaner. Let dwell 10-15 minutes keeping surface wet. Scrub heavily soiled areas.'],
                    ['title' => 'Pressure Washing', 'description' => 'Rinse with 1,200-1,500 PSI for softwoods, up to 2,500 PSI for hardwoods. Use wide fan tip 12-18 inches from surface. Work with the grain, never against it.'],
                    ['title' => 'Stripping Old Finish', 'description' => 'If previous stain or paint is failing, apply chemical stripper and agitate with a stiff brush. Pressure wash to remove. Multiple applications may be needed for heavy film-forming stains.'],
                    ['title' => 'Brightening', 'description' => 'Apply wood brightener (oxalic acid) to restore natural color and open grain for stain penetration. This neutralizes pH after alkaline cleaners.'],
                    ['title' => 'Drying Period', 'description' => 'Allow wood to dry to below 15% moisture content. In Denver, this takes 24-48 hours after washing. Do not stain wet wood -- trapped moisture prevents penetration.'],
                    ['title' => 'Masking and Protection', 'description' => 'Cover landscaping, concrete, siding, and adjacent surfaces. Stain splatter on concrete is very difficult to remove. Protect plants with plastic sheeting.'],
                    ['title' => 'Stain Selection and Testing', 'description' => 'Choose between transparent (1-2 year life), semi-transparent (2-4 years), or solid stain (3-5 years). For Denver, semi-transparent or solid stains are strongly recommended due to extreme UV. Test on an inconspicuous area first.'],
                    ['title' => 'Stain Application', 'description' => 'Apply stain in the direction of the wood grain. Always back-brush after applying by roller or sprayer to work stain into the wood. Work in small sections maintaining a wet edge. Do not allow puddles.'],
                    ['title' => 'Application Timing', 'description' => 'Apply when wood is in shade or overcast. In Denver, this means early morning (before 10 AM) or late afternoon (after 4 PM) in summer. Never apply to sun-heated wood -- it dries on the surface without penetrating.'],
                    ['title' => 'Drying and Curing', 'description' => 'Allow 24-48 hours before foot traffic, 72 hours to one week for full cure. Place furniture pads under deck furniture to prevent imprinting during cure.'],
                ],
                'tips' => [
                    ['title' => 'Penetrating oil-based stains outperform film-forming stains', 'description' => 'Film-forming stains crack and peel in Denver\'s temperature cycling. Penetrating stains flex with expansion/contraction. When they wear, they fade gradually and can be recoated without stripping.'],
                    ['title' => 'UV protection is not optional -- use pigmented stain', 'description' => 'Clear sealers last only 6-12 months in Denver. The pigment in stain acts like sunscreen for wood. Semi-transparent stains provide moderate UV blocking. Clear sealers are effectively a waste of money in Colorado sun.'],
                    ['title' => 'Recoat every 2-3 years for decks', 'description' => 'Horizontal surfaces take far more UV and weather abuse than vertical surfaces. Plan deck floors every 2-3 years, fence panels every 3-4 years. South-facing fences degrade faster.'],
                    ['title' => 'Never stain in direct sun', 'description' => 'Denver sun heats wood to 130-150 degrees F at midday. Stain applied to hot wood dries on the surface without penetrating, producing a sticky, blotchy finish. Use the hand test -- if uncomfortably warm, wait.'],
                ],
                'cost_factors' => [
                    'Square footage of deck and fence area',
                    'Wood type -- softwoods absorb quickly; hardwoods require specialized products',
                    'Existing finish condition -- bare wood is simplest; stripping old paint adds major labor',
                    'Stain type -- transparent stains are cheapest but need most frequent reapplication',
                    'Height and accessibility -- tall fences and multi-level decks increase labor',
                    'Structural repairs -- board replacement and hardware upgrades add costs',
                    'Product quality -- premium penetrating stains ($40-70/gallon) vs. box-store stains ($25-40/gallon)',
                ],
                'faqs' => [
                    ['q' => 'How often do I need to restain my deck in Denver?', 'a' => 'Plan to restain deck floors every 2-3 years with a quality penetrating stain. Vertical surfaces like railings can go 3-4 years. Clear sealers last under a year. The best indicator is the water absorption test: if water soaks in instead of beading up, it is time to recoat.'],
                    ['q' => 'What is the best stain type for Colorado?', 'a' => 'Penetrating oil-based semi-transparent stain is the best all-around choice. It soaks into the wood, does not crack or peel, and fades gracefully. Top brands: TWP, Ready Seal, Armstrong-Clark, and Cabot Australian Timber Oil. Avoid water-based stains on horizontal deck surfaces.'],
                    ['q' => 'Can I stain my deck in the fall?', 'a' => 'Yes, September through mid-October is actually ideal -- moderate temperatures, lower UV, past hail season. The constraint is nighttime temperatures: stain needs to stay above 50 degrees F for 24-48 hours after application.'],
                    ['q' => 'Should I stain or paint my fence?', 'a' => 'Staining is almost always preferable in Colorado. Paint cracks and peels as wood expands and contracts. Once paint peels, the entire fence must be scraped. Stain penetrates and wears gradually, and recoating requires only cleaning and a fresh coat.'],
                    ['q' => 'Do I need to sand my deck before staining?', 'a' => 'Full sanding is generally not necessary and can be counterproductive by closing the grain. The key prep steps are cleaning, pressure washing, and brightening. Sand only specific problem areas like splinters and flaking spots.'],
                ],
            ],

            'garage-floor-coating' => [
                'title' => 'Garage Floor Coating',
                'category' => 'Residential',
                'tagline' => 'Durable epoxy and polyurea garage floor coatings engineered for Colorado conditions',
                'meta_description' => 'Professional garage floor coating in Denver. Epoxy and polyurea systems with decorative flakes, UV resistance, and hot-tire pickup prevention. Free estimates from Painting Made Easy Denver.',
                'overview' => 'A professional garage floor coating transforms a stained, cracking concrete slab into a durable, attractive surface that resists chemicals, hot tires, and years of abuse. In Colorado, where garage floors endure road salt, magnesium chloride, freeze-thaw cycling, and UV from open doors, the coating system matters enormously.

The industry standard for Colorado garage floors is an epoxy base coat with a polyaspartic topcoat. This combination provides epoxy\'s excellent adhesion and thickness with polyurea\'s UV resistance and cold-temperature flexibility. Standard DIY epoxy kits yellow within 1-2 years of UV exposure and become brittle in cold temperatures -- both deal-breakers in Denver.

Surface preparation is the most critical step. Diamond grinding the concrete creates a proper mechanical bond that prevents delamination. Acid etching is a budget alternative but significantly less reliable. The most common failure in garage floor coatings is peeling due to inadequate surface preparation.',
                'materials' => [
                    'Coating Products' => [
                        'Hybrid system: epoxy base coat + polyaspartic topcoat (most popular professional system)',
                        '100% solids epoxy system (ArmorPoxy, Rust-Oleum Professional) or polyurea system',
                        'Decorative vinyl color flakes (1/4" or 1/8" size)',
                        'Concrete crack filler and patching compound',
                    ],
                    'Surface Preparation' => [
                        'Walk-behind concrete floor grinder with diamond segments (rental)',
                        'HEPA shop vacuum for dust collection',
                        'Concrete moisture test kit and infrared thermometer',
                        'Concrete degreaser for oil stain removal',
                    ],
                    'Application Tools' => [
                        '18-inch roller frames with 3/8" nap solvent-resistant covers',
                        'Extension pole, chip brush for edge cutting',
                        'Mixing drill with paddle attachment, 5-gallon buckets',
                        'Spike shoes for walking on wet coating',
                    ],
                    'Safety' => [
                        'Full-face respirator with organic vapor cartridges',
                        'Chemical-resistant nitrile gloves and safety glasses',
                        'Adequate ventilation fans',
                    ],
                ],
                'steps' => [
                    ['title' => 'Garage Assessment', 'description' => 'Empty the garage completely. Inspect concrete for cracks, spalling, oil stains, previous coatings, and moisture issues. New concrete must cure at least 30 days before coating.'],
                    ['title' => 'Moisture Testing', 'description' => 'Tape a 2x2 ft sheet of plastic to the floor and check for condensation after 24-48 hours. Colorado\'s dry climate generally reduces issues, but slab-on-grade construction can still have moisture vapor transmission.'],
                    ['title' => 'Crack and Damage Repair', 'description' => 'Fill cracks with polyurea or epoxy crack filler. Grind down high spots. Fill spalled areas with self-leveling repair compound. In Denver\'s freeze-thaw climate, crack repair prevents water from entering and causing further damage.'],
                    ['title' => 'Oil and Stain Removal', 'description' => 'Treat oil stains with concrete degreaser. Deep stains may need a poultice left overnight. Oil prevents coating adhesion and surrounding area will delaminate if not fully removed.'],
                    ['title' => 'Surface Profiling (Grinding)', 'description' => 'Diamond grind the concrete to CSP-2 or CSP-3 for proper mechanical bond. This is the most critical step. The floor should feel like fine-grit sandpaper. Vacuum all dust with HEPA shop vacuum.'],
                    ['title' => 'Temperature and Conditions Check', 'description' => 'Verify both air and concrete temperature are 50-85 degrees F for epoxy, 35-100 degrees F for polyurea. Use infrared thermometer on the floor itself -- slab temperature often differs from air temperature.'],
                    ['title' => 'Mix and Apply Base Coat', 'description' => 'Mix two-part epoxy per manufacturer ratios. Note pot life -- 30-45 minutes for epoxy, 15-20 for polyurea. Cut in edges with brush, then roll from back of garage toward door.'],
                    ['title' => 'Broadcast Decorative Flakes', 'description' => 'While coating is still wet, broadcast vinyl flakes by hand. Full-broadcast creates a textured, decorative finish. Timing is critical -- flakes on tacky coating will not embed properly.'],
                    ['title' => 'Allow Base Coat to Cure', 'description' => 'Epoxy requires 12-24 hours; polyurea may be ready in 4-6 hours. After curing, scrape off proud flakes and vacuum before topcoat.'],
                    ['title' => 'Apply Clear Topcoat', 'description' => 'Apply polyurea or polyaspartic topcoat for UV resistance, chemical resistance, and glossy finish. This topcoat is what provides abrasion resistance and prevents hot-tire pickup.'],
                    ['title' => 'Final Cure', 'description' => 'Foot traffic in 12-24 hours. Vehicle traffic in 3-5 days for epoxy, 24-48 hours for polyurea. Do not drive on the floor before full cure -- hot tires will pull the coating off.'],
                ],
                'tips' => [
                    ['title' => 'Polyurea beats standard epoxy for Colorado garages', 'description' => 'Standard epoxy yellows in UV and becomes brittle in cold. Denver garages see temperature extremes and direct sun. The industry standard is epoxy base coat with polyaspartic topcoat -- combining thickness with UV resistance.'],
                    ['title' => 'Concrete slab temperature matters more than air temperature', 'description' => 'A north-facing garage slab may be 50 degrees F when air is 70. Use an infrared thermometer on the slab itself. Epoxy on cold concrete will not cure; on hot concrete it cures too fast with air bubbles.'],
                    ['title' => 'Schedule for late spring or early fall', 'description' => 'Ideal conditions are 55-75 degrees F -- May-June and September-October in Denver. Avoid midsummer (slab too hot) and winter (slab too cold).'],
                    ['title' => 'De-icing chemicals are the winter enemy', 'description' => 'Denver roads are heavily treated with magnesium chloride. Your car drags this into the garage. Ensure your topcoat is rated for chemical resistance. A quality polyurea topcoat handles road chemicals far better than standard epoxy.'],
                ],
                'cost_factors' => [
                    'Garage size -- 1-car, 2-car, or 3-car',
                    'Coating type -- standard epoxy ($3-6/sqft) vs. polyurea/polyaspartic ($7-12/sqft)',
                    'Surface condition -- cracked, oil-stained, or previously coated floors require more prep',
                    'Preparation method -- diamond grinding (better results) vs. acid etching',
                    'Decorative flakes -- partial vs. full-chip broadcast vs. metallic epoxy',
                    'Previous coating removal -- grinding off old epoxy adds significant labor',
                ],
                'faqs' => [
                    ['q' => 'How long does a garage floor coating last in Colorado?', 'a' => 'A professional polyurea/polyaspartic system lasts 15-20+ years. DIY epoxy kits last 3-5 years before yellowing and hot-tire pickup. The difference is product quality and surface preparation. Professional systems are 3-4x thicker than retail kits, and diamond grinding creates a superior bond.'],
                    ['q' => 'Can I coat my garage floor myself?', 'a' => 'DIY kits are available but differ significantly from professional systems. They use thinner products, less durable coatings, and rely on acid etching. If you go DIY, rent a concrete grinder instead of acid etching -- it dramatically improves results. For best results and longest life, professional installation is worth the investment.'],
                    ['q' => 'What about hot-tire pickup?', 'a' => 'Hot-tire pickup occurs when heated tires soften the coating. It happens primarily with thin, water-based, or single-coat applications. Prevent it with 100% solids epoxy or polyurea, proper diamond grinding, adequate thickness (8-12 mils minimum), and full cure time. A polyaspartic topcoat is the best defense.'],
                    ['q' => 'How long does installation take?', 'a' => 'Professional installation takes 1-2 days. Day 1: grinding, crack repair, cleaning. Day 2: coating and flakes. For polyurea, light use the next day and vehicles in 24-48 hours. For epoxy, vehicles should wait 5-7 days.'],
                ],
            ],

            'popcorn-ceiling-removal' => [
                'title' => 'Popcorn Ceiling Removal & Repainting',
                'category' => 'Residential',
                'tagline' => 'Modernize your home by removing dated popcorn texture for smooth, clean ceilings',
                'meta_description' => 'Professional popcorn ceiling removal and repainting in Denver. Asbestos testing, skim coating, and smooth finish ceilings. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Popcorn ceilings were popular from the 1950s through the 1980s, but today they make homes look dated and can harbor dust, cobwebs, and even asbestos. Removing popcorn texture and replacing it with a smooth, modern ceiling is one of the most popular renovation projects in established Denver neighborhoods.

The process involves wetting and scraping the texture, repairing the underlying drywall, skim coating to create a smooth surface, priming, and painting. It is one of the messiest home improvement projects, requiring full room preparation with heavy plastic sheeting.

Safety is the first priority. Homes built before 1981 frequently contain asbestos in ceiling texture. Testing is mandatory before any scraping begins. If asbestos is confirmed, licensed abatement is legally required. Denver\'s dry air is actually helpful for this project -- joint compound dries faster, allowing multiple skim coats in a single day.',
                'materials' => [
                    'Safety and Testing' => [
                        'Asbestos test kit or professional testing service',
                        'N95 respirator and safety glasses',
                        'Protective clothing and coveralls',
                    ],
                    'Removal Tools' => [
                        'Pump sprayer (1-2 gallon) for wetting texture',
                        'Wide drywall scraping knives (8", 10", 12")',
                        'Ceiling texture scraper with handle',
                        'Scoring tool for painted popcorn',
                    ],
                    'Repair and Finishing' => [
                        'Premixed all-purpose joint compound (multiple 5-gallon buckets)',
                        '12-inch and 14-inch drywall taping knives for skim coating',
                        'Drywall tape for re-taping loose joints',
                        '150-grit sandpaper and pole sander, work light for raking technique',
                    ],
                    'Painting' => [
                        'PVA drywall primer or shellac-based primer',
                        'Premium ceiling paint (flat/matte finish)',
                        '9-inch rollers with 3/8" or 1/2" nap, extension pole',
                        '6-mil heavy-duty plastic sheeting for room protection',
                    ],
                ],
                'steps' => [
                    ['title' => 'Asbestos Testing (Critical)', 'description' => 'Homes built before 1981 frequently contain asbestos in ceiling texture. Collect a small sample (misted with water) and send to a lab. NEVER scrape without testing first. If positive, hire licensed asbestos abatement.'],
                    ['title' => 'Room Preparation', 'description' => 'Remove all furniture. Remove ceiling fixtures and smoke detectors. Cover floors with 6-mil plastic sheeting, cover walls to prevent splatter. Turn off HVAC to prevent dust circulation. This is extremely messy.'],
                    ['title' => 'Determine If Ceiling Has Been Painted', 'description' => 'Spray a test area with water. If texture softens, it is unpainted and removal is straightforward. If water beads up, it has been painted and requires scoring tools or potentially skim coating over instead.'],
                    ['title' => 'Wet the Ceiling', 'description' => 'Spray 4x4 foot sections with warm water from a pump sprayer. Let soak 15-20 minutes until mushy. In Denver, work in smaller sections because water evaporates faster.'],
                    ['title' => 'Scrape the Texture', 'description' => 'Using a wide drywall knife, scrape softened texture at a low angle to avoid gouging drywall. For stubborn areas, re-spray and soak longer. For painted popcorn, score the paint layer first.'],
                    ['title' => 'Remove All Residue', 'description' => 'Go over the entire ceiling with the scraper and a damp sponge to remove remaining bumps and adhesive. Allow to dry completely (2-4 hours in Denver).'],
                    ['title' => 'Inspect and Repair Substrate', 'description' => 'Popcorn texture hides imperfections. Expect to find gouges, popped screws, and loose joint tape. Reset screws, re-tape loose joints, fill damage with joint compound.'],
                    ['title' => 'Skim Coat the Ceiling', 'description' => 'Apply thin skim coat of joint compound across the entire ceiling with a 12-14 inch taping knife. This creates a uniform surface over residual texture and patches.'],
                    ['title' => 'Sand the Skim Coat', 'description' => 'Sand with 150-grit on a pole sander. Use a work light at a low angle (raking light) to reveal every imperfection. Vacuum dust.'],
                    ['title' => 'Second Skim Coat', 'description' => 'Apply a second thin coat focusing on remaining imperfections. Professional smooth ceilings typically require 2-3 skim coats, each sanded before the next.'],
                    ['title' => 'Prime the Ceiling', 'description' => 'Apply PVA drywall primer to seal joint compound and create a uniform base. Use shellac-based primer over any stains. In Denver, primer dries in 1-2 hours.'],
                    ['title' => 'Paint Two Coats', 'description' => 'Apply two coats of flat ceiling paint with a roller, perpendicular to the primary light source. Allow full drying between coats. Use Floetrol to extend open time and prevent lap marks in Denver\'s dry air.'],
                    ['title' => 'Reinstall Fixtures', 'description' => 'Reinstall lights, ceiling fans, and smoke detectors. Remove all plastic and clean up. Inspect under various lighting conditions.'],
                ],
                'tips' => [
                    ['title' => 'Denver\'s dry air speeds skim coating', 'description' => 'Joint compound dries faster in Denver\'s low humidity, allowing multiple skim coats in a single day. What takes two days at sea level can be done in one long day. But do not sand until fully dry -- test by pressing with your fingernail.'],
                    ['title' => 'Use less water per section', 'description' => 'Water evaporates faster in Denver. Work in smaller 3x3 foot sections instead of 4x4. Have the sprayer ready to re-mist. Over-soaking is actually harder in Denver because moisture evaporates quickly.'],
                    ['title' => 'Dust management is critical', 'description' => 'Sanding in dry air creates fine dust that stays airborne for hours. Use a pole sander attached to a shop vacuum with HEPA filter. Seal the room and consider running a portable air scrubber.'],
                    ['title' => 'Watch for ceiling paint lap marks', 'description' => 'Ceiling paint can start to tack within minutes in Denver. Use Floetrol to extend open time. Roll the entire ceiling in one session without stopping -- dried edges will show lap marks.'],
                ],
                'cost_factors' => [
                    'Room size -- square footage of ceiling area',
                    'Ceiling height -- vaulted or cathedral ceilings require scaffolding',
                    'Asbestos presence -- licensed abatement adds $5-20/sqft',
                    'Painted vs. unpainted texture -- painted popcorn is harder and costlier to remove',
                    'Finish level -- basic smooth (1-2 skim coats) vs. Level 5 perfect finish',
                    'Number of rooms -- economies of scale apply',
                ],
                'faqs' => [
                    ['q' => 'Is popcorn ceiling removal messy?', 'a' => 'Yes, it is one of the messiest home improvement projects. Wet texture falls in chunks and splatters. Professional crews use full room containment with plastic on all surfaces. The dry-sanding phase is also extremely dusty. Budget time for thorough cleanup.'],
                    ['q' => 'How do I know if my popcorn ceiling has asbestos?', 'a' => 'It must be tested -- you cannot tell by looking. Homes built before 1981 have significant risk. Homes 1981-1990 may also contain asbestos. Homes after 1990 are very unlikely. Scrape a quarter-sized sample and send to a certified lab. Never scrape without testing.'],
                    ['q' => 'Can I just paint over popcorn ceiling?', 'a' => 'Yes -- it is faster, cheaper, and avoids asbestos risk. Use a thick-nap roller and two coats of flat ceiling paint. However, painting does not modernize the look. Most homebuyers prefer smooth ceilings, and removal adds more home value.'],
                    ['q' => 'How long does the process take?', 'a' => 'For a single average room, 1-2 days: Day 1 for removal, repair, and first skim coat. Day 2 for sanding, second skim, priming, and painting. A full house (5-8 rooms) takes 5-10 working days. Denver\'s dry air helps compress the schedule.'],
                ],
            ],

            'drywall-repair' => [
                'title' => 'Drywall Repair & Patching',
                'category' => 'Residential',
                'tagline' => 'Invisible drywall repairs that match your existing walls perfectly',
                'meta_description' => 'Professional drywall repair and patching in Denver. Hole repair, crack fixing, texture matching, and seamless painting. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Drywall damage is inevitable in every home -- nail holes, doorknob impacts, furniture dings, settling cracks, and water damage all take their toll. The good news is that nearly any drywall damage can be repaired to an invisible finish with the right technique and materials.

The real skill in drywall repair is not the patching itself but making the repair invisible. Texture matching, proper priming, and painting the entire wall (not just the patch) are what separate professional results from visible DIY patches. In Denver, the most common drywall issues include nail pops from framing lumber shrinking in the dry air and seasonal cracks from the extreme temperature and humidity cycling.

Denver\'s low humidity is helpful in one respect: joint compound dries faster, allowing faster turnaround between coats. Setting-type compound (hot mud) is especially useful because it cures by chemical reaction rather than evaporation, drying uniformly throughout thick applications.',
                'materials' => [
                    'Repair Materials' => [
                        'Lightweight spackle for small holes',
                        'All-purpose joint compound (premixed) for medium and large repairs',
                        'Setting-type joint compound (45-min or 90-min) for deep fills',
                        'Self-adhesive fiberglass mesh patches, drywall tape, drywall pieces',
                    ],
                    'Tools' => [
                        'Putty knives (1.5", 3", 4" flexible) and drywall taping knives (6", 8", 10", 12")',
                        'Drywall saw and oscillating multi-tool for clean cuts',
                        'Drill/driver and drywall screws for backing strips',
                        'Sanding sponges and sandpaper (120, 150, 220 grit)',
                    ],
                    'Texturing Tools' => [
                        'Texture hopper gun and compressor for orange peel',
                        'Spray can of matching texture for small repairs',
                        'Knockdown knife and sponges for blending',
                    ],
                    'Painting' => [
                        'PVA drywall primer',
                        'Matching wall paint, roller and brushes',
                    ],
                ],
                'steps' => [
                    ['title' => 'Assess the Damage', 'description' => 'Identify damage type and size: small holes (under 1/2 inch), medium holes (1/2 to 6 inches), large holes (over 6 inches), cracks, or water damage. For water damage, fix the source before repairing.'],
                    ['title' => 'Prepare the Damaged Area', 'description' => 'Remove loose or crumbling material. For cracks, widen with a utility knife (V-groove) to give compound something to grip. For water damage, cut out all soft or mold-affected drywall back to solid material.'],
                    ['title' => 'Small Hole Repair', 'description' => 'Apply lightweight spackle directly with a putty knife, slightly overfilling. Smooth and feather edges. Dry 30-60 minutes, then sand smooth with 150-grit.'],
                    ['title' => 'Medium Hole Repair', 'description' => 'Use a self-adhesive mesh patch or California patch (cut drywall piece with paper flange extending beyond gypsum). Apply joint compound in multiple thin coats, extending further each time. Sand between coats.'],
                    ['title' => 'Large Hole Repair', 'description' => 'Cut damage into a clean rectangle. Install wood backing strips behind the opening. Cut new drywall to fit, secure with screws. Apply mesh or paper tape to seams. Multiple thin coats of joint compound, feathering wider each time.'],
                    ['title' => 'Crack Repair', 'description' => 'Apply mesh tape or paper tape bedded in compound over the crack. Apply compound in multiple coats with wider knives. For recurring cracks from structural movement, use flexible compound or mesh tape.'],
                    ['title' => 'Texture Matching', 'description' => 'Identify existing texture (smooth, orange peel, knockdown, skip trowel). Practice on cardboard first. Apply with appropriate tool -- hopper gun for orange peel, knife and wet sponge for knockdown.'],
                    ['title' => 'Priming', 'description' => 'Prime the repair with PVA drywall primer. Without primer, the repair will flash as a different sheen or color. Extend primer 2-3 inches beyond the repair.'],
                    ['title' => 'Painting', 'description' => 'Ideally, paint the entire wall corner-to-corner for the best match. Spot-painting often shows as a visible patch even with the correct color due to sheen and application differences.'],
                    ['title' => 'Final Inspection', 'description' => 'Inspect under multiple lighting conditions -- natural daylight, overhead, and raking-angle light. The repair should be invisible from 3-4 feet.'],
                ],
                'tips' => [
                    ['title' => 'Use setting-type joint compound in Denver', 'description' => 'Premixed compound dries by evaporation and can remain soft underneath thick applications in dry air. Setting-type compound cures by chemical reaction, drying uniformly. Use it for deep fills and first coats; switch to premixed for final skim because it sands easier.'],
                    ['title' => 'Temperature cycling causes seasonal cracks', 'description' => 'Denver homes see significant interior temperature and humidity fluctuations. Cracks above doorways and at wall/ceiling junctions are common from wood framing movement. Use flexible joint compound or fiberglass mesh tape for recurring cracks.'],
                    ['title' => 'Low humidity means more dust', 'description' => 'Drywall compound dries harder in Denver, producing finer dust when sanded. Use a shop vacuum attached to your sander and wear an N95 respirator. Seal the room from HVAC.'],
                    ['title' => 'Nail pops are a Denver specialty', 'description' => 'Dry air causes framing lumber to shrink, pushing nails through the surface. Fix by driving new screws 1-2 inches above and below the pop, setting the nail below surface, and filling with compound.'],
                ],
                'cost_factors' => [
                    'Damage size and quantity -- small nail holes vs. large drywall replacement',
                    'Location and accessibility -- ceiling repairs cost more than wall repairs',
                    'Texture matching -- smooth walls are easiest; complex textures require skill',
                    'Water damage extent -- saturated or moldy drywall must be fully replaced',
                    'Finish level -- basic functional repair vs. invisible repair with full-wall painting',
                    'Painting scope -- spot touch-up vs. repainting entire walls for seamless match',
                ],
                'faqs' => [
                    ['q' => 'Can I fix drywall holes myself?', 'a' => 'Small holes are very achievable DIY with spackle and a putty knife. Medium holes are moderate with mesh patches. Large holes require cutting in new drywall and are more challenging. The hardest part is making repairs invisible -- texture matching and paint blending take experience.'],
                    ['q' => 'Why do I keep getting cracks in the same spots?', 'a' => 'Recurring cracks are usually from structural movement: foundation settling in Denver\'s expansive clay soils, seasonal wood framing movement from humidity changes, or inadequate framing. Flexible mesh tape can accommodate minor movement, but persistent cracking warrants a structural inspection.'],
                    ['q' => 'How long does a drywall repair take?', 'a' => 'Small holes take 30 minutes spread over 24 hours. Medium holes take 2-3 hours over 2-3 days. Large holes take 3-5 hours over 3-4 days due to multiple compound coats and drying. In Denver, drying times are shortened to 2-4 hours between coats.'],
                    ['q' => 'What is the difference between spackle and joint compound?', 'a' => 'Spackle is lightweight, quick-drying filler for small repairs (under 1/2 inch). It dries in 30-60 minutes but is not strong enough for larger repairs. Joint compound is stronger, can be built up in thicker layers, and is standard for taping and larger repairs. Setting-type compound is strongest for deep fills.'],
                ],
            ],

            'trim-door-painting' => [
                'title' => 'Trim, Door & Molding Painting',
                'category' => 'Residential',
                'tagline' => 'Crisp, smooth trim and doors that frame every room with a professional finish',
                'meta_description' => 'Professional trim, door, and molding painting in Denver. Hybrid alkyd enamel for smooth, durable finishes on baseboards, crown molding, doors, and casings. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Trim painting is the detail work that elevates a room from good to great. Fresh, crisp trim makes walls look better, rooms feel cleaner, and the entire home feel more polished. Baseboards, crown molding, door casings, window casings, and doors all benefit from quality trim paint applied with precision.

The key to great trim is using hybrid alkyd/acrylic enamel paint like Benjamin Moore Advance or Sherwin-Williams Emerald Urethane Trim Enamel. These products self-level for a smooth finish, cure to a hard, durable surface, and clean up with soap and water. In Denver\'s dry air, their longer open time compared to straight latex is critical for achieving brush-mark-free results.

Denver\'s low humidity creates specific challenges: paint skins over before it can self-level, caulk shrinks more than in humid climates, and doors can warp if edges are left unpainted. Understanding these conditions and using the right products makes the difference between professional results and visible brush marks.',
                'materials' => [
                    'Paint and Primer' => [
                        'Trim paint (Benjamin Moore Advance, Sherwin-Williams Emerald Urethane, or ProClassic)',
                        'Primer (Zinsser BIN shellac for stain blocking, KILZ Adhesion for spot priming)',
                        'Paintable acrylic latex caulk (DAP Big Stretch for Denver\'s temperature cycling)',
                    ],
                    'Brushes' => [
                        '2.5-inch angled sash brush (primary trim brush -- Purdy, Wooster, or Corona)',
                        '2-inch and 1.5-inch angled sash brushes for tight and detail areas',
                        'Small 4-6 inch foam rollers for flat door panels',
                    ],
                    'Preparation' => [
                        '150-grit and 220-grit sandpaper and sanding sponges',
                        'Lightweight spackle or wood filler, putty knives',
                        'TSP or household cleaner for degreasing',
                        'Painter\'s tape (FrogTape) and masking film',
                    ],
                    'Hardware Tools' => [
                        'Screwdriver set for removing door hardware',
                        'Zip-lock bags and labels for hardware organization',
                    ],
                ],
                'steps' => [
                    ['title' => 'Inventory and Assessment', 'description' => 'Walk through and inventory all trim: baseboards, crown molding, chair rail, door casings, window casings, doors, closet doors, stair railings. Note condition and measure linear feet.'],
                    ['title' => 'Select Paint and Finish', 'description' => 'Choose semi-gloss or satin finish. Semi-gloss is traditional for trim (durable, wipeable). Benjamin Moore Advance or Sherwin-Williams Emerald Urethane are top choices for their self-leveling properties.'],
                    ['title' => 'Clean All Surfaces', 'description' => 'Wipe all trim with TSP solution to remove dust, grime, and fingerprint oils. Clean tops of casings and baseboards where dust collects. Rinse and let dry.'],
                    ['title' => 'Remove or Mask Hardware', 'description' => 'Remove door hinges, knobs, and window hardware whenever possible -- painting around hardware produces sloppy results. Mask walls along baseboard edges with painter\'s tape.'],
                    ['title' => 'Surface Repair', 'description' => 'Fill nail holes and dents with spackle. Caulk gaps between trim pieces and between trim and wall with paintable caulk. This caulking is what gives trim a finished, professional look.'],
                    ['title' => 'Sand All Surfaces', 'description' => 'Sand with 150-grit to degloss existing finish and create adhesion tooth. Use sanding sponges for profiles and corners. Vacuum and wipe with tack cloth.'],
                    ['title' => 'Prime Where Needed', 'description' => 'Prime bare wood, filler, and stained areas. Use shellac primer (Zinsser BIN) over previously stained wood to block tannin bleed that causes yellow staining through white paint.'],
                    ['title' => 'Paint Doors', 'description' => 'Remove doors and lay flat on sawhorses. For panel doors, paint panels first, then rails, then stiles, then edges. Use foam roller for flat surfaces and brush for edges. Apply thin coats. Two coats minimum.'],
                    ['title' => 'Paint Crown Molding', 'description' => 'Start at the top of the room. Use a 2-2.5 inch angled brush, cutting in along ceiling and wall lines. Follow the contours of the profile. Work in sections maintaining a wet edge.'],
                    ['title' => 'Paint Window and Door Casings', 'description' => 'Work from inside out -- paint edge closest to window/door first, then casing face, then outside edge. For double-hung windows: inner sash, outer sash, frame, then casing.'],
                    ['title' => 'Paint Baseboards', 'description' => 'Paint baseboards last. Use a 2-2.5 inch angled brush. Apply with smooth, even strokes in one direction. Use the "lay on, lay off" technique for a level finish.'],
                    ['title' => 'Second Coat', 'description' => 'Allow first coat to dry (16-24 hours for hybrid alkyd). Lightly sand imperfections with 220-grit. Apply second coat -- non-negotiable for opacity, depth, and durability.'],
                    ['title' => 'Reinstall and Inspect', 'description' => 'Remove tape, reinstall hardware, rehang doors and adjust hinges. Inspect under strong lighting and touch up with an artist\'s brush.'],
                ],
                'tips' => [
                    ['title' => 'Hybrid alkyd paints are the best choice for Denver trim', 'description' => 'Oil-based dries too slowly and skins over in dry air, causing wrinkling. Straight latex dries too fast, leaving brush marks. Hybrid alkyd (Advance) is the sweet spot -- self-leveling properties with latex cleanup.'],
                    ['title' => 'Extend dry time on doors', 'description' => 'Paint can start to skin within minutes in Denver. Work quickly, maintain a wet edge, and do not go back over areas that have started to set up. Advance gives 15-20 minutes of open time in Denver versus 30-40 in humid climates.'],
                    ['title' => 'Caulk shrinks more in dry air', 'description' => 'Latex caulk shrinks by releasing moisture. In Denver\'s 20-30% RH, caulk shrinks more than in humid climates. Use DAP Big Stretch and apply a slightly larger bead. Check and reapply the next day if gaps reappear.'],
                    ['title' => 'Paint all door edges', 'description' => 'In Colorado\'s dry climate, unsealed door edges absorb moisture at different rates, causing warping. Always paint all edges including the bottom edge -- moisture wicking through unpainted bottom edges is a leading cause of door warping.'],
                ],
                'cost_factors' => [
                    'Linear feet of trim -- baseboards, crown molding, chair rail',
                    'Number of doors -- each takes 1-2 hours for a professional',
                    'Number of windows -- multi-pane windows take significantly more time',
                    'Trim condition -- heavy prep vs. light sand-and-paint',
                    'Profile complexity -- flat trim is faster than ornate multi-profile molding',
                    'Paint type -- hybrid alkyd ($55-80/gallon) vs. standard latex ($35-55/gallon)',
                ],
                'faqs' => [
                    ['q' => 'What is the best paint for interior trim in Denver?', 'a' => 'Benjamin Moore Advance and Sherwin-Williams Emerald Urethane Trim Enamel are the top choices. Both are hybrid alkyd formulations that self-level for a smooth finish and cure hard. Advance has longer open time, making it slightly more forgiving in Denver\'s dry air.'],
                    ['q' => 'Should trim be painted before or after walls?', 'a' => 'Most professionals paint walls first, then trim. The trim brush work creates a crisp, clean line that serves as the final visual edge. Some pros prefer trim first. Either approach works if executed carefully -- the key is that the last thing painted creates the clean line.'],
                    ['q' => 'How do I avoid brush marks on trim and doors?', 'a' => 'Use hybrid alkyd paint with Floetrol to extend open time. Use a high-quality brush (Purdy, Wooster). Load one-third of bristle length. Apply with moderate pressure and the "lay on, lay off" technique. For door panels, use a foam roller for flat areas and brush only edges.'],
                    ['q' => 'How long does trim paint take to fully cure?', 'a' => 'Hybrid alkyd is dry to touch in 16-24 hours and can receive a second coat after 16 hours. Full cure (maximum hardness) takes 30 days. During this period, avoid scrubbing and close doors gently. In Denver, surface drying is faster but the oxidative cure follows the same timeline.'],
                ],
            ],

            'wallpaper-removal' => [
                'title' => 'Wallpaper Removal & Repainting',
                'category' => 'Residential',
                'tagline' => 'Remove outdated wallpaper and transform your walls with fresh, modern paint',
                'meta_description' => 'Professional wallpaper removal and repainting in Denver. Expert adhesive removal, wall repair, and seamless painting. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Wallpaper removal is one of those projects that sounds simple but can quickly become challenging, especially in older Denver homes. Multiple layers of wallpaper, decades-old paste dried rock-hard by Colorado\'s dry air, and drywall damage from improper installation can all add complexity and time.

The process involves scoring (if needed), soaking with removal solution, scraping, removing all adhesive residue, repairing wall damage, priming, and painting. The adhesive removal step is critical -- any residue left on the wall will cause primer and paint to bubble, peel, and fail.

Denver\'s dry climate affects every step of this process. Removal solution evaporates faster, requiring frequent reapplication. Old paste is more desiccated and brittle. Walls dry faster after removal but must still be given 24 hours minimum before priming. The silver lining: Denver\'s dry air also means less risk of mold growth behind wallpaper compared to humid climates.',
                'materials' => [
                    'Removal Products' => [
                        'Wallpaper removal solution (DIF liquid or gel)',
                        'Fabric softener or white vinegar as alternatives',
                        'Wallpaper steamer (rental for large jobs)',
                        'Pump sprayer (2-gallon) and sponges',
                    ],
                    'Removal Tools' => [
                        'Wallpaper scoring tool (Paper Tiger)',
                        'Flexible putty knives and scrapers (3", 4", 6")',
                        'Wide wallpaper scraper (10-12")',
                        'Contractor garbage bags',
                    ],
                    'Repair Supplies' => [
                        'Joint compound (premixed)',
                        'Drywall taping knives (6", 10")',
                        '150-grit and 220-grit sandpaper, sanding sponges',
                    ],
                    'Priming and Painting' => [
                        'High-adhesion primer (Zinsser BIN shellac, Zinsser Gardz, or KILZ Original)',
                        'Interior wall paint (premium acrylic latex)',
                        'Rollers, brushes, and Floetrol paint conditioner',
                        'Canvas drop cloths and 6-mil plastic sheeting',
                    ],
                ],
                'steps' => [
                    ['title' => 'Identify Wallpaper Type', 'description' => 'Test by lifting a corner. Peel-and-stick peels cleanly (easiest). Strippable vinyl-coated has a top layer that peels, leaving backing to soak. Traditional pasted paper requires full soaking. Painted-over wallpaper is the most difficult.'],
                    ['title' => 'Prepare the Room', 'description' => 'Remove furniture and outlet covers. Cover floors with canvas and plastic (this process involves a lot of water). Tape plastic over baseboards. Turn off electricity if water will be near outlets.'],
                    ['title' => 'Score the Wallpaper', 'description' => 'For painted-over or vinyl-coated wallpaper, use a scoring tool to create perforations that allow removal solution to reach the adhesive. Apply consistent, moderate pressure to avoid gouging drywall.'],
                    ['title' => 'Apply Removal Solution', 'description' => 'Apply generously with pump sprayer. Work in manageable sections. Let soak 15-20 minutes. In Denver, keep surfaces wet by reapplying frequently -- the dry air evaporates the solution faster.'],
                    ['title' => 'Scrape the Wallpaper', 'description' => 'Starting at seams, slide a flexible putty knife under the paper at a shallow angle. Paper should come off in large pieces if adequately soaked. For stubborn areas, re-wet or use a steamer.'],
                    ['title' => 'Remove Backing Layer', 'description' => 'Many wallpapers leave a paper backing after the face is removed. Re-wet with solution, wait 10-15 minutes, and scrape with gentle pressure.'],
                    ['title' => 'Remove All Adhesive Residue', 'description' => 'This is critical -- residual adhesive causes paint failure. Scrub with removal solution and Scotch-Brite pad. Use the squeak test: run a clean wet finger across the wall. If it squeaks, adhesive is gone. If slippery, more cleaning is needed.'],
                    ['title' => 'Allow Walls to Dry', 'description' => 'Wait 24 hours minimum even though Denver\'s dry air may make walls feel dry in 12 hours. The drywall absorbed significant water and painting over damp walls causes adhesion failure and mold.'],
                    ['title' => 'Inspect and Repair Damage', 'description' => 'Wallpaper removal almost always reveals damage: gouges, torn drywall paper, popped seams. Seal torn paper with Zinsser Gardz or BIN. Fill gouges with joint compound. If damage is extensive, skim-coat the entire wall.'],
                    ['title' => 'Sand Walls Smooth', 'description' => 'Lightly sand with 150-grit to create uniform texture. Focus on areas where paper was damaged. Vacuum dust.'],
                    ['title' => 'Prime All Walls', 'description' => 'Use shellac-based or oil-based primer (not water-based, which can reactivate adhesive residue). Zinsser Gardz is excellent for damaged paper -- it penetrates and hardens fuzzy fibers.'],
                    ['title' => 'Paint Two Coats', 'description' => 'Apply two coats of wall paint. If adhesive bleeds through as yellow spots, spot-prime with BIN shellac and repaint. Use Floetrol for Denver\'s dry conditions.'],
                ],
                'tips' => [
                    ['title' => 'Walls dry faster but do not rush', 'description' => 'Denver\'s dry air pulls moisture from wet drywall quickly, but moisture penetrates deep into the core. If you prime too soon, trapped moisture causes bubbling and mold. Wait 24 hours minimum.'],
                    ['title' => 'Adhesive removal is harder in dry air', 'description' => 'Removal solution evaporates faster in Denver. Work in smaller sections and reapply frequently. If adhesive dries before you can scrub it, it re-solidifies and becomes harder to remove.'],
                    ['title' => 'Old paste becomes rock-hard in Denver', 'description' => 'In homes where wallpaper has been up for decades, Denver\'s dry air desiccates paste to a rock-hard film. Sometimes it can be scraped dry before wetting -- this is faster and introduces less moisture to drywall.'],
                    ['title' => 'Zinsser Gardz is your secret weapon', 'description' => 'Wallpaper removal frequently tears the drywall paper face. Gardz is a clear penetrating sealer that soaks into damaged fibers and hardens them, creating a solid paintable surface even on severely damaged paper.'],
                ],
                'cost_factors' => [
                    'Room size -- square footage of wallpapered surface',
                    'Wallpaper type -- peel-and-stick is cheapest; painted-over is most expensive to remove',
                    'Number of layers -- multiple layers multiply removal time',
                    'Adhesive condition -- some dissolve easily; decades-old paste in dry Denver air is tenacious',
                    'Wall condition after removal -- minimal damage vs. extensive skim-coating needed',
                    'Painting scope -- removal only vs. full prep and painting to completion',
                ],
                'faqs' => [
                    ['q' => 'Can I paint over wallpaper instead of removing it?', 'a' => 'You can but it is not recommended. Use oil-based primer first (water-based can reactivate paste and cause bubbling). Wallpaper texture and seams will show through. If the wallpaper ever peels, the paint peels with it. For quality results, removal is always better.'],
                    ['q' => 'How long does wallpaper removal take?', 'a' => 'Strippable wallpaper in a 12x14 room takes 4-6 hours. Vinyl-coated or painted-over wallpaper takes 8-12 hours. Multiple layers multiply the time. Wall repair, priming, and painting add 1-2 additional days.'],
                    ['q' => 'Will my walls look good after removal?', 'a' => 'Nearly all removal results in some wall damage -- it is normal. The amount depends on whether walls were primed before wallpaper was applied, how many layers exist, and paste age. Professional work always includes repair, priming, and painting -- removal is only half the job.'],
                    ['q' => 'What primer should I use after removal?', 'a' => 'Shellac-based (Zinsser BIN) or oil-based (KILZ Original) for strongest adhesion and stain blocking. Water-based primers can reactivate residual adhesive. For damaged paper, apply Gardz first, then BIN over stains. Never use "paint and primer in one" over stripped walls.'],
                    ['q' => 'My wallpaper was applied over unpainted drywall. What should I expect?', 'a' => 'This is the worst-case scenario. The paste bonds directly to drywall paper. Removal tears off the top layer, leaving a fuzzy surface. The wall needs Gardz sealer, skim-coating (2-3 coats), sanding, priming, and painting. This is common in older Denver homes.'],
                ],
            ],

            // ============================================================
            // COMMERCIAL SERVICES (10-13)
            // ============================================================
            'commercial-interior' => [
                'title' => 'Commercial Interior Painting',
                'category' => 'Commercial',
                'tagline' => 'Professional painting for offices, retail spaces, and restaurants with minimal disruption',
                'meta_description' => 'Commercial interior painting in Denver. After-hours and phased painting for offices, retail, and restaurants using low-VOC paints. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Commercial interior painting requires a different approach than residential work. Business operations, tenant coordination, air quality requirements, and tight timelines all factor into the planning. The most important question is not what color to paint, but how to get it done with zero disruption to your business.

We specialize in occupied-space painting using phased approaches: working zone-by-zone, after hours, or during scheduled closures. Low-VOC and zero-VOC paints are essential for occupied commercial spaces -- at Denver\'s altitude, lower atmospheric pressure causes VOCs to off-gas more readily, making odor complaints and health concerns more significant than at sea level.

Denver\'s 300+ days of sunshine create another consideration: south and west-facing walls with large windows receive intense UV through glass, causing interior paint to fade faster. UV-resistant formulations are worth specifying for these exposures, especially for accent colors like reds, yellows, and deep blues.',
                'materials' => [
                    'Paint and Coatings' => [
                        'Interior latex paint in flat, eggshell, satin, and semi-gloss sheens',
                        'Low-VOC or zero-VOC formulations for occupied spaces',
                        'Primer (PVA, stain-blocking, and bonding as needed)',
                        'Paintable acrylic latex caulk and lightweight spackle',
                    ],
                    'Application Tools' => [
                        'Airless sprayer for unoccupied spaces, rollers for occupied areas',
                        'High-quality roller frames (9" and 18") with appropriate nap covers',
                        'Angled sash brushes (2.5" and 3") for cutting in',
                        'Extension poles (4-16 ft) and spray shields',
                    ],
                    'Access Equipment' => [
                        'Step ladders (4, 6, 8 ft) and extension ladders for stairwells',
                        'Baker scaffold or scaffolding for high ceilings over 12 ft',
                        'Scissor lift for open areas with ceilings 15+ ft',
                    ],
                    'Protection' => [
                        'Canvas drop cloths and Ram board for high-traffic corridors',
                        'Plastic sheeting for furniture, electronics, and server rooms',
                        'Masking supplies for fire alarm stations, sprinklers, and thermostats',
                    ],
                ],
                'steps' => [
                    ['title' => 'Site Assessment and Scope Definition', 'description' => 'Walk the entire facility documenting square footage, ceiling heights, surface types, and current condition. Identify electrical panels, fire suppression systems, and HVAC returns that require masking.'],
                    ['title' => 'Color Selection and Specification', 'description' => 'Confirm colors, sheens, and brands. Specify low-VOC or zero-VOC paints for occupied buildings. Provide on-site color samples under actual lighting conditions.'],
                    ['title' => 'Scheduling and Tenant Coordination', 'description' => 'Develop a phased work schedule minimizing disruption. Options: after-hours, zone-by-zone, or full-closure. Provide written notice to tenants 3-5 business days before work begins.'],
                    ['title' => 'Protection and Masking', 'description' => 'Cover floors with canvas drop cloths. Mask switches, outlets, thermostats, fire alarm stations. Cover server rooms and electronics with sealed plastic. Use Ram board in high-traffic corridors.'],
                    ['title' => 'Surface Cleaning', 'description' => 'Wash surfaces to remove dust, grease, and contaminants. Kitchen and restaurant areas require TSP degreaser. Clean HVAC registers and all surfaces receiving paint.'],
                    ['title' => 'Surface Repairs', 'description' => 'Fill holes and cracks. Repair damaged drywall sections. Re-caulk all trim-to-wall and ceiling-to-wall joints. Sand all patches smooth.'],
                    ['title' => 'Priming', 'description' => 'Apply stain-blocking primer over water stains and smoke damage. Bonding primer over glossy surfaces. PVA primer on new drywall. Full-coat when changing from dark to light colors.'],
                    ['title' => 'Cutting In', 'description' => 'Cut in all edges with angled brush -- ceiling lines, corners, trim, outlets, switches. Maintain wet edge to prevent lap marks. Use extendable tools for high walls.'],
                    ['title' => 'First Coat Application', 'description' => 'Apply using airless sprayer (unoccupied) or roller (occupied). Back-roll sprayed surfaces for uniform texture. Coverage: 350-400 sq ft per gallon on smooth surfaces.'],
                    ['title' => 'Second Coat Application', 'description' => 'Allow proper dry time (2-4 hours). Apply second coat -- two coats minimum is industry standard. Inspect between coats for holidays and drips.'],
                    ['title' => 'Detail Work and Touch-Up', 'description' => 'Paint all trim, doors, and frames (typically semi-gloss). Two coats on detail work. Remove masking tape before final coat is fully cured for cleanest lines.'],
                    ['title' => 'Inspection and Punch List', 'description' => 'Walkthrough with client under normal lighting. Create and address punch list before sign-off.'],
                    ['title' => 'Cleanup and Handover', 'description' => 'Remove all coverings, clean paint drips, move furniture back. Provide leftover paint labeled by room and color code, maintenance instructions, and warranty.'],
                ],
                'tips' => [
                    ['title' => 'Altitude drying adjustment', 'description' => 'Denver\'s low humidity causes paint to dry 30-50% faster. Add Floetrol to slow drying and improve leveling. Run a humidifier to maintain 40-50% RH in the workspace.'],
                    ['title' => 'Temperature swing management', 'description' => 'Interior spaces near exterior walls experience Denver\'s 40-degree daily swings. Ensure HVAC is running during application and curing. Paint will not cure if wall surface temperatures drop below 50 degrees F overnight.'],
                    ['title' => 'Low-VOC is essential at altitude', 'description' => 'Lower atmospheric pressure at 5,280 feet means VOCs off-gas more readily. Use zero-VOC paints in occupied spaces. This is both a health consideration and practical -- odor complaints increase at altitude.'],
                    ['title' => 'Dust control is critical', 'description' => 'Colorado\'s dry climate generates more airborne dust. Seal off work areas with poly sheeting. A single dust particle in wet paint on a high-sheen surface creates a visible defect.'],
                ],
                'cost_factors' => [
                    'Square footage of paintable surfaces (wall and ceiling area, not floor space)',
                    'Ceiling height -- standard 8-9 ft vs. 15+ ft requiring lifts',
                    'Surface condition and prep requirements',
                    'Work schedule restrictions -- after-hours and weekend work adds 15-30% labor premium',
                    'Occupied vs. unoccupied -- occupied requires phasing and extra protection',
                    'Number of colors -- each change requires equipment cleaning and setup',
                    'Trim and detail work quantity -- doors, frames, baseboards',
                ],
                'faqs' => [
                    ['q' => 'Can you paint while we stay open for business?', 'a' => 'Yes. We work in zones, completing one area before moving to the next. For restaurants and retail, we typically work overnight. For offices, we can work room-by-room using low-VOC, low-odor paints safe for occupied spaces. We coordinate a detailed schedule so there are zero surprises.'],
                    ['q' => 'How long does a commercial interior paint job take?', 'a' => 'A small office suite (2,000-3,000 sq ft) takes 3-5 days. Mid-size retail (5,000-10,000 sq ft) takes 1-2 weeks. Large spaces (20,000+ sq ft) take 3-6 weeks. After-hours schedules extend timelines by 50-75%.'],
                    ['q' => 'What paint finish should I use for commercial areas?', 'a' => 'High-traffic areas use eggshell or satin for washability. Offices and conference rooms use eggshell. Restrooms and kitchens use semi-gloss for moisture resistance. Ceilings use flat. Trim and doors use semi-gloss for durability.'],
                    ['q' => 'How often should commercial interiors be repainted?', 'a' => 'High-traffic spaces (restaurants, retail, medical) need repainting every 3-5 years. Standard offices last 5-7 years. Low-traffic areas go 7-10 years. Touch-up maintenance every 1-2 years extends any paint job significantly.'],
                    ['q' => 'What about paint fumes and air quality?', 'a' => 'We use low-VOC or zero-VOC paints meeting Green Seal GS-11 standards. We ensure proper ventilation and use portable air movers. Most people can return to a freshly painted area within 2-4 hours with zero discomfort.'],
                ],
            ],

            'commercial-exterior' => [
                'title' => 'Commercial Exterior Painting',
                'category' => 'Commercial',
                'tagline' => 'Protect your commercial building with coatings engineered for Denver\'s altitude',
                'meta_description' => 'Commercial exterior painting in Denver. UV-resistant coatings, OSHA-compliant safety, scaffolding and lift work for buildings of all sizes. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Commercial exterior painting at Denver\'s altitude demands UV-resistant coatings, proper access equipment, and climate-aware scheduling. At 5,280+ feet, buildings receive 25% more UV radiation than sea level, causing standard paints to fade 2-3 times faster. South and west-facing elevations may need recoating a full cycle earlier than other walls.

The reliable exterior painting window in Denver is late April through mid-October. Denver\'s extreme diurnal temperature swings create a narrow daily window: surface temperature must be above 50 degrees F at application AND overnight lows must stay above 35-40 degrees F. This means stopping paint application by 3-4 PM in shoulder seasons.

Safety is paramount on commercial projects. All crews must be OSHA 10/30 certified with fall protection for work above 6 feet. Scaffolding, boom lifts, and swing stages require proper setup and inspection. We coordinate permits for sidewalk closures, traffic control, and equipment staging.',
                'materials' => [
                    'Paint and Coatings' => [
                        '100% acrylic latex exterior paint (Sherwin-Williams Duration, Benjamin Moore Aura Exterior)',
                        'Elastomeric coating for stucco/masonry where specified',
                        'Direct-to-metal (DTM) acrylic or alkyd enamel for metal surfaces',
                        'Exterior primers (alkali-resistant, rust-inhibiting, bonding, stain-blocking)',
                    ],
                    'Application Equipment' => [
                        'Commercial airless sprayer (Graco GMax 7900 or similar)',
                        'Multiple spray guns with varied tip sizes',
                        '18" roller frames with extension handles and heavy-nap covers',
                        'Angled sash brushes (2.5"-4")',
                    ],
                    'Access Equipment' => [
                        'Scaffolding (frame, system, or suspended swing stage)',
                        'Articulating boom lift (40-60 ft) and telescoping boom lift (60-135 ft)',
                        'Extension ladders (28-40 ft)',
                    ],
                    'Safety Equipment' => [
                        'Full body harnesses with lanyards and SRLs',
                        'Hard hats, safety glasses, N95/P100 respirators',
                        'High-visibility vests and fall protection anchor systems',
                    ],
                ],
                'steps' => [
                    ['title' => 'Comprehensive Exterior Assessment', 'description' => 'Inspect the entire building envelope. Document substrate types, surface defects, previous coating adhesion, and total paintable area on all elevations.'],
                    ['title' => 'Color Selection and Design', 'description' => 'Work with client on color schemes. Check local zoning and historic district regulations. Provide large samples applied directly to the building -- colors read differently under Denver\'s intense sunlight.'],
                    ['title' => 'Permits and Logistics', 'description' => 'Obtain permits for scaffolding, sidewalk closures, and aerial lift placement. Coordinate traffic control and material staging areas.'],
                    ['title' => 'Site Preparation and Protection', 'description' => 'Set up scaffolding or lifts. Install ground protection over sidewalks, landscaping, and parking areas. Mask windows, doors, signage, and mechanical equipment. Install OSHA-compliant fall protection.'],
                    ['title' => 'Pressure Washing', 'description' => 'Pressure wash all surfaces with appropriate PSI for each substrate. Apply mildew treatment. Allow 24-48 hours drying time.'],
                    ['title' => 'Surface Repairs', 'description' => 'Repair stucco cracks, patch concrete damage, replace rotted wood. Treat rust on metal surfaces. Re-caulk all joints with commercial-grade sealant.'],
                    ['title' => 'Scraping and Sanding', 'description' => 'Scrape all loose paint. Feather-sand transitions. On pre-1978 buildings, test for lead paint and follow EPA RRP rules if positive.'],
                    ['title' => 'Priming', 'description' => 'Apply substrate-appropriate primer: alkali-resistant on masonry, rust-inhibiting on metal, bonding on chalky surfaces, stain-blocking over tannin bleed.'],
                    ['title' => 'First Coat Application', 'description' => 'Apply first coat with commercial airless sprayer. Back-roll on textured surfaces. Work from top to bottom, in shade when possible.'],
                    ['title' => 'Second Coat Application', 'description' => 'Apply second coat after 4-6 hours minimum dry time. Three coats may be needed for severe exposure or dark-over-light changes.'],
                    ['title' => 'Detail and Accent Painting', 'description' => 'Paint trim, fascia, soffits, downspouts, storefronts, doors, and accent features per design specification.'],
                    ['title' => 'Quality Inspection', 'description' => 'Inspect all surfaces from ground and access equipment. Walk all elevations with client. Complete punch list before equipment demobilization.'],
                    ['title' => 'Cleanup and Documentation', 'description' => 'Remove all equipment and masking. Clean overspray. Provide completion documentation with colors, warranty, and maintenance recommendations.'],
                ],
                'tips' => [
                    ['title' => 'UV-resistant paint is non-negotiable', 'description' => 'At 5,280 feet, standard paints fade 2-3 times faster. Specify paints with advanced UV-resistant resin technology. Exterior paint in Denver lasts 5-7 years vs. 7-10 at sea level. South and west elevations degrade fastest.'],
                    ['title' => 'The 50-degree overnight rule', 'description' => 'Surface temperature must be above 50 degrees F at application AND overnight low must stay above 35-40 degrees F. This limits the season to late April through mid-October. Stop applying by 3-4 PM in shoulder seasons.'],
                    ['title' => 'Wind is the hidden enemy', 'description' => 'Denver averages 8-10 MPH with gusts to 40 MPH. Wind causes spray drift and deposits dust in wet paint. Use wind screens on scaffolding and switch to roller/brush above 15 MPH.'],
                    ['title' => 'Monsoon season awareness', 'description' => 'July through September brings afternoon thunderstorms. Schedule morning-only spray work during this period. Rain on uncured paint within 4-6 hours causes washout requiring full reapplication.'],
                ],
                'cost_factors' => [
                    'Building size and total paintable surface area on all elevations',
                    'Building height and access requirements -- 1-2 story vs. 3+ requiring lifts/scaffolding',
                    'Substrate type and condition -- smooth stucco vs. heavily textured block',
                    'Prep intensity -- light wash vs. extensive scraping, patching, and caulking',
                    'Number of colors and complexity -- single body vs. multi-color scheme',
                    'Permit and traffic control requirements',
                    'Seasonal timing -- peak season May-September commands higher rates',
                ],
                'faqs' => [
                    ['q' => 'How long does exterior commercial paint last in Denver?', 'a' => 'Due to intense UV and temperature swings, exterior paint lasts 5-7 years on commercial buildings. South and west walls may show fading at 3-4 years. Premium paints with UV inhibitors push the upper range. Elastomeric coatings on stucco can last 7-10 years.'],
                    ['q' => 'Can you paint while the building remains open?', 'a' => 'Absolutely. We plan work to minimize disruption. Entry points stay clear and accessible. We use signage and barricades for safety. We can prioritize storefronts for overnight or weekend work.'],
                    ['q' => 'Can you paint just the faded sides?', 'a' => 'Yes. Partial repaints of south and west elevations are a common cost-saving strategy in Denver. We match existing colors for seamless appearance and can save 40-60% compared to a full building repaint.'],
                    ['q' => 'What about safety for tall buildings?', 'a' => 'All crews are OSHA 10/30 certified with comprehensive liability and workers\' comp insurance. We use OSHA-compliant fall protection, certified scaffolding, and maintain a written safety plan for each project.'],
                ],
            ],

            'warehouse-industrial' => [
                'title' => 'Warehouse & Industrial Painting',
                'category' => 'Commercial',
                'tagline' => 'Epoxy floors, OSHA safety markings, and industrial coatings for your facility',
                'meta_description' => 'Warehouse and industrial painting in Denver. Epoxy floor coatings, OSHA safety line striping, structural steel painting, and wall coatings. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Warehouse and industrial painting covers a wide scope: epoxy floor coatings for forklift traffic, OSHA-compliant safety color coding and line striping, structural steel and column painting, and wall and ceiling coatings for light reflectivity and cleanliness.

OSHA compliance is a primary driver. Safety color coding per 29 CFR 1910.144 requires specific colors for specific hazards: red for fire protection and danger, yellow for caution, orange for dangerous machine parts. Aisle markings per 29 CFR 1910.176 must be at least 4 feet wide or 3 feet wider than the largest equipment. Non-compliance results in citations and fines.

Industrial floor coatings in Denver face unique conditions. Concrete slabs can be extremely cold well into spring. Epoxy pot life is shorter at altitude due to faster curing. Lower atmospheric pressure affects spray equipment performance. But Denver\'s dry air is actually beneficial for concrete moisture -- lower ambient humidity means less moisture migration through slabs.',
                'materials' => [
                    'Floor Coatings' => [
                        'Two-component epoxy floor primer and topcoat (100% solids or high-solids)',
                        'Polyurethane or polyaspartic topcoat for UV and abrasion resistance',
                        'Anti-slip aggregate (aluminum oxide, polymer grit)',
                        'Epoxy crack filler and patching compound',
                    ],
                    'Wall and Steel Coatings' => [
                        'High-build acrylic latex for walls',
                        'DTM (Direct-to-Metal) primer and enamel for structural steel',
                        'Rust-inhibiting primer (zinc-rich or red oxide)',
                    ],
                    'Line Striping' => [
                        'OSHA-compliant epoxy line paint (yellow, white, red, blue, green, orange)',
                        'Line striping machine (walk-behind airless or roller type)',
                        'Stencil kits (letters, numbers, arrows, ADA symbols)',
                    ],
                    'Equipment' => [
                        'Shot blaster or diamond grinder for floor preparation',
                        'Commercial airless sprayer for walls',
                        'Scissor and boom lifts for high-bay ceilings',
                        'HEPA vacuum, moisture testing equipment',
                    ],
                ],
                'steps' => [
                    ['title' => 'Facility Assessment and OSHA Review', 'description' => 'Survey walls, structural steel, floors, and columns. Identify OSHA compliance requirements for safety colors and aisle markings. Document operational constraints including active forklifts and production schedules.'],
                    ['title' => 'Specification Development', 'description' => 'Select coating systems for each environment: epoxy for floors, DTM for steel, acrylic for walls. Define OSHA colors, line widths (4 inches standard), and aisle widths (minimum 4 feet).'],
                    ['title' => 'Phased Work Plan', 'description' => 'Develop painting schedule that keeps the facility operational. Coordinate with plant management and safety director. Establish lockout/tagout procedures near machinery.'],
                    ['title' => 'Floor Preparation', 'description' => 'Diamond grind or shot blast concrete to ICRI CSP 2-4 profile. Degrease oil-contaminated areas. Test moisture (below 4 lbs/1000 sqft/24hrs). Fill cracks with epoxy patching compound.'],
                    ['title' => 'Wall and Steel Preparation', 'description' => 'Clean warehouse walls. Remove loose rust from steel using power tools to SSPC-SP3 standard. Sand glossy surfaces.'],
                    ['title' => 'Floor Coating - Base and Top Coat', 'description' => 'Apply epoxy primer to prepared concrete. Allow cure (12-24 hours). Apply epoxy or polyurethane topcoat. Add anti-slip aggregate in pedestrian areas. Allow 24-72 hours cure before forklift traffic.'],
                    ['title' => 'Safety Line Striping', 'description' => 'Snap chalk lines for traffic lanes, walkways, equipment zones. Apply floor marking lines using OSHA-compliant colors. Yellow for traffic lanes, red for fire equipment, white for rack locations.'],
                    ['title' => 'Wall and Ceiling Painting', 'description' => 'Spray walls using high-build acrylic. Apply wainscot coating (to 4-6 feet) in epoxy for forklift contact areas. Bright white ceilings improve ambient light and reduce energy costs.'],
                    ['title' => 'Column and Steel Painting', 'description' => 'Paint columns with high-visibility colors (yellow to 48 inches for forklift visibility per OSHA). Apply DTM primer and enamel on structural steel.'],
                    ['title' => 'Signage and Stenciling', 'description' => 'Apply stenciled text for NO PARKING, FIRE LANE, KEEP CLEAR, directional arrows, and zone identifiers using contrasting colors.'],
                    ['title' => 'Inspection and Documentation', 'description' => 'Walkthrough with safety manager. Verify all OSHA color coding. Test floor adhesion. Document all markings with photographs and a floor plan diagram.'],
                ],
                'tips' => [
                    ['title' => 'Concrete moisture at altitude', 'description' => 'Denver\'s dry air is beneficial for floor coating -- less moisture migration. But always test: new concrete needs 28-day minimum cure. Slabs without vapor barriers can still trap snowmelt moisture. Test every 500 sq ft.'],
                    ['title' => 'Epoxy pot life is shorter here', 'description' => 'Two-component epoxies cure faster in Denver\'s conditions. A 45-minute pot life at sea level may only give 30-35 minutes at altitude. Mix smaller batches and have more rollers ready.'],
                    ['title' => 'Ventilation calculations at altitude', 'description' => 'At 5,280 feet, air is 17% less dense. Fans move less mass of air. Increase ventilation capacity by 15-20% above calculated requirements for spray operations.'],
                    ['title' => 'Cold floor challenges', 'description' => 'Warehouse concrete can be below 50 degrees F well into May. Epoxy will not cure below 50. Use infrared thermometers on the slab, not air temperature. Temporary heating may be needed.'],
                ],
                'cost_factors' => [
                    'Total square footage (floor, walls, ceiling, steel priced separately)',
                    'Floor coating system complexity -- simple sealer vs. multi-coat epoxy with topcoat',
                    'Surface preparation requirements -- clean concrete vs. oil-contaminated floors',
                    'Ceiling height and access -- standard 16-20 ft vs. high-bay 30-50+ ft',
                    'OSHA compliance scope -- new markings from scratch vs. refreshing existing',
                    'Operational downtime constraints -- weekend/holiday work, phased approach',
                    'Safety line striping quantity -- linear footage and stencil count',
                ],
                'faqs' => [
                    ['q' => 'Can you paint while operations continue?', 'a' => 'Yes, we specialize in phased industrial painting. We work in zones, completing sections over weekends or scheduled shutdowns. Floor coating requires the area clear for 24-72 hours. Wall and ceiling work can happen during operations with proper containment.'],
                    ['q' => 'What are the OSHA requirements for floor markings?', 'a' => 'OSHA requires all permanent aisles to be "appropriately marked." Yellow for traffic lanes, red for fire equipment, orange for dangerous machine parts. Lines must be 2-6 inches wide (4 inches standard). Aisles at least 4 feet wide or 3 feet wider than the largest equipment. Non-compliance results in citations and fines.'],
                    ['q' => 'How long does epoxy floor coating last?', 'a' => 'Properly applied industrial epoxy lasts 5-10 years in heavy forklift traffic, 10-20 years in pedestrian areas. Adding a polyurethane topcoat increases life by 30-50%. Regular maintenance maximizes lifespan.'],
                    ['q' => 'How long do floor markings last?', 'a' => 'Epoxy-based line striping lasts 2-4 years under heavy forklift traffic. Traffic-grade enamel lasts 6-12 months. We recommend annual inspection and touch-up to maintain OSHA compliance.'],
                ],
            ],

            'hoa-multiunit' => [
                'title' => 'HOA & Multi-Unit Painting',
                'category' => 'Commercial',
                'tagline' => 'Community-wide exterior painting with phased scheduling and HOA board coordination',
                'meta_description' => 'HOA and multi-unit exterior painting in Denver. Board presentations, CC&R compliance, phased scheduling, and wood rot repair for apartment and condo communities. Free estimates from Painting Made Easy Denver.',
                'overview' => 'HOA and multi-unit painting projects are some of the most complex painting jobs, requiring coordination with boards, architectural review committees, property managers, and residents. The painting itself is just one piece -- communication, scheduling, color approval, and repair management are equally important.

Colorado\'s Front Range has thousands of HOA communities, many built during the 1990s-2010s construction boom with stucco and wood-sided buildings that now need attention. Denver\'s freeze-thaw cycles (100+ per year) combined with intense UV create extreme wood deterioration. What looks like surface peeling often reveals advanced rot underneath. Budgeting 10-20% for wood repairs on communities over 15 years old is standard.

The reliable exterior painting season in Denver is late April through mid-October. Large HOA projects (50+ units) may need to be phased across two painting seasons. We guide boards through the process from initial assessment and color selection through final walkthrough and documentation.',
                'materials' => [
                    'Paint and Coatings' => [
                        'Premium 100% acrylic exterior latex (body, trim, accent colors)',
                        'DTM primer and enamel for railings and metal elements',
                        'Exterior primers (bonding, stain-blocking, wood)',
                        'Exterior caulk (paintable polyurethane or elastomeric)',
                    ],
                    'Repair Materials' => [
                        'Pressure-treated lumber for structural repairs',
                        'Primed trim boards and fascia stock',
                        'Fiber cement replacement pieces',
                        'Wood filler and epoxy wood consolidant',
                    ],
                    'Equipment' => [
                        'Multiple airless sprayers for simultaneous building crews',
                        'Extension ladders (24-40 ft) and scaffolding sections',
                        'Articulating boom lifts for 3-story buildings',
                    ],
                    'Communication' => [
                        'Resident notification door hangers and building schedule signage',
                        '"Wet Paint" warning signs and barricade tape',
                    ],
                ],
                'steps' => [
                    ['title' => 'Community Assessment', 'description' => 'Inspect every building documenting conditions by building, elevation, and unit. Create a condition rating (1-5 scale) for each building to help the HOA prioritize.'],
                    ['title' => 'CC&R Review and Color Approval', 'description' => 'Review community covenants and architectural guidelines. Present color recommendations with large samples applied to representative buildings. Navigate the approval process with the ARC.'],
                    ['title' => 'Board Presentation', 'description' => 'Prepare detailed proposal with scope breakdown by building, phasing options, multi-year plans, and good-better-best options. Include reserve study alignment.'],
                    ['title' => 'Resident Notification', 'description' => 'Distribute written notices to every unit 2-4 weeks before work. Post signage at entrances. Address common concerns: noise, access, parking, pet safety, timeline.'],
                    ['title' => 'Mobilization and Staging', 'description' => 'Establish staging area for materials and equipment. Coordinate gate access, parking restrictions, and dumpster placement. Post building-by-building schedule.'],
                    ['title' => 'Pressure Washing', 'description' => 'Pressure wash building by building. Apply mildew treatment to all affected areas. Notify residents 24 hours before their building to close windows and move patio items.'],
                    ['title' => 'Carpentry and Repairs', 'description' => 'Replace rotted wood trim, fascia, soffits, and siding. Repair stucco cracks. Fix railings and handrails. Document all repairs with photos for HOA records.'],
                    ['title' => 'Preparation and Masking', 'description' => 'Scrape loose paint, sand edges, clean surfaces. Mask windows, lights, and unit numbers. Protect walkways, landscaping, and personal property.'],
                    ['title' => 'Priming', 'description' => 'Spot-prime all bare wood and repairs. Full-coat prime for significant color changes. Use tannin-blocking primer on cedar or redwood.'],
                    ['title' => 'Body Color (Two Coats)', 'description' => 'Apply two coats of body color to all siding. Mix large batches to maintain consistency across buildings. Ensure uniform coverage on every building.'],
                    ['title' => 'Trim and Accent Painting', 'description' => 'Two coats of trim color on fascia, soffits, window trim, corner boards. Paint accent colors on doors and shutters. DTM coating on railings and metal elements.'],
                    ['title' => 'Building-by-Building Quality Control', 'description' => 'Inspect each building before moving crews. Walkthrough with HOA representative. Create and close punch list for each building.'],
                    ['title' => 'Final Walkthrough and Closeout', 'description' => 'Community-wide inspection with Board. Provide complete documentation: color map, specs, warranty, and maintenance schedule. Provide labeled leftover paint.'],
                ],
                'tips' => [
                    ['title' => 'Plan for Denver\'s short painting season', 'description' => 'The reliable window is late April through mid-October. Large projects (50+ units) may need two seasons. Mountain communities above 6,500 feet have an even shorter window -- May through September. Start planning in January.'],
                    ['title' => 'Wood rot is rampant in Colorado', 'description' => 'Freeze-thaw cycles combined with UV create extreme wood deterioration. What looks like surface peeling often reveals rot underneath. Budget 10-20% for wood repairs on communities over 15 years old. Recommend fiber cement replacements for worst areas.'],
                    ['title' => 'Stucco communities need elastomeric', 'description' => 'Standard acrylic paint does not adequately protect stucco from freeze-thaw cracking. Elastomeric coatings cost more upfront but extend repaint cycle from 5-7 years to 8-12 years and prevent costly moisture intrusion.'],
                    ['title' => 'Sun-exposed elevations degrade faster', 'description' => 'South and west-facing elevations can be 2-3 years ahead in degradation. Propose a maintenance strategy addressing sun-exposed sides more frequently, saving 30-40% over a 10-year period.'],
                ],
                'cost_factors' => [
                    'Number of units and buildings in the community',
                    'Building height and style -- 1-story ranch vs. 3-story walk-up',
                    'Substrate type and condition -- fiber cement vs. deteriorated wood vs. cracked stucco',
                    'Scope of wood repair and replacement -- often the single largest variable cost',
                    'Number of colors in the scheme -- 2-color vs. 4+ with accents',
                    'Coating specification -- standard acrylic vs. elastomeric on stucco',
                    'Community size and phasing -- economy of scale vs. multi-season costs',
                ],
                'faqs' => [
                    ['q' => 'How often should our HOA community be repainted?', 'a' => 'Most Denver communities need full repainting every 7-10 years. Stucco without elastomeric needs 5-7 years. Wood-sided buildings 5-8 years. Fiber cement with premium paint can stretch to 10-15 years. Annual touch-ups extend the cycle significantly.'],
                    ['q' => 'Our budget is tight. What are our options?', 'a' => 'We offer several approaches: phase over 2-3 years doing worst buildings first, paint only sun-exposed elevations, use good-quality instead of premium paint, or separate body and trim into different years. We work with HOA boards regularly on balancing quality with budget.'],
                    ['q' => 'How do you minimize resident disruption?', 'a' => 'No work before 8 AM or after 6 PM weekdays, before 9 AM weekends. 48-hour advance notice per building. Work areas cleaned daily. Clear walkways and parking maintained. A dedicated project manager handles all resident concerns.'],
                    ['q' => 'Who decides the colors?', 'a' => 'The HOA Board and/or Architectural Review Committee have final say per the community\'s CC&Rs. We provide professional color consultation, including large-scale samples applied to the building. We guide the approval process and recommend schemes appropriate for the architecture and Denver\'s intense sunlight.'],
                ],
            ],

            // ============================================================
            // SPECIALTY SERVICES (14-17)
            // ============================================================
            'line-striping' => [
                'title' => 'Parking Lot & Line Striping',
                'category' => 'Specialty',
                'tagline' => 'ADA-compliant parking lot striping, fire lane marking, and traffic flow design',
                'meta_description' => 'Parking lot striping and line marking in Denver. ADA-compliant accessible spaces, fire lane marking, directional arrows, and pavement signage. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Parking lot striping is a critical maintenance item that affects safety, compliance, and curb appeal. ADA-accessible space requirements, fire lane markings, and traffic flow design are all governed by specific codes and regulations. Non-compliance can result in fines up to $150,000 for ADA violations.

Denver\'s climate accelerates marking deterioration. UV at altitude fades markings 30-40% faster than sea level, and 50+ inches of annual snow means plow damage every winter. Standard water-based traffic paint that lasts 18-24 months elsewhere may only last 12-15 months in Denver. Thermoplastic markings are more cost-effective long-term for high-visibility areas like fire lanes and accessible spaces.

Proper ADA compliance is critical in Colorado, where the Attorney General has been active in enforcement. Beyond paint markings, accessible routes, signage with state-mandated fine amounts, and proper slope measurements all factor into compliance.',
                'materials' => [
                    'Striping Paint' => [
                        'Traffic-rated water-based paint (white, yellow, blue, red)',
                        'Solvent-based traffic paint for cold or damp conditions',
                        'Thermoplastic and applicator for high-durability requirements',
                        'Reflective glass beads for retroreflective markings',
                    ],
                    'Equipment' => [
                        'Walk-behind airless line striper (Graco or Titan)',
                        'Stencil sets: wheelchair symbol (36" and 48"), arrows, letters, numbers',
                        'Chalk line reels, measuring wheels, tape measures (100 ft and 300 ft)',
                    ],
                    'Surface Prep' => [
                        'Power broom or sweeper',
                        'Asphalt degreaser and primer',
                        'Line removal equipment (grinder or water blaster)',
                    ],
                    'Signage' => [
                        'ADA-compliant parking signs with ISA symbol and fines',
                        'Fire lane signs, sign posts, and mounting hardware',
                        'Traffic cones, barricades, and delineators for lot closures',
                    ],
                ],
                'steps' => [
                    ['title' => 'Site Survey and Compliance Audit', 'description' => 'Count total spaces and calculate required ADA-accessible spaces per 2010 ADA Standards. Document existing markings, signage, and non-compliant conditions. Note pavement condition and slopes.'],
                    ['title' => 'Layout Design', 'description' => 'Create a scaled layout showing all spaces, accessible spaces, access aisles, fire lanes, arrows, crosswalks, and stop bars. Verify accessible spaces are on the shortest route to building entrances.'],
                    ['title' => 'ADA Space Dimensioning', 'description' => 'Standard accessible: 96 inches wide minimum. Van-accessible: 132 inches wide. Access aisle: 60 inches minimum. Maximum 2.08% slope in all directions. Located on shortest accessible route.'],
                    ['title' => 'Surface Preparation', 'description' => 'Sweep entire lot. Remove old markings if layout is changing. If recently sealcoated, allow minimum 24-48 hours cure before striping. Clean oil stains with degreaser.'],
                    ['title' => 'Chalk Layout', 'description' => 'Snap chalk lines for all boundaries. Standard spaces typically 8.5-9 feet wide by 18-20 feet deep. Verify all measurements. Mark stencil locations.'],
                    ['title' => 'Standard Space Striping', 'description' => 'Stripe all standard stalls with walk-behind striper. Standard line width: 4 inches. Use traffic-rated paint compatible with pavement type.'],
                    ['title' => 'Accessible Space Striping', 'description' => 'Paint accessible space borders, diagonal hatching in access aisles, and wheelchair symbol stencils (minimum 36 inches tall). Stencil "NO PARKING" in aisles and "VAN ACCESSIBLE" where required.'],
                    ['title' => 'Fire Lane Marking', 'description' => 'Mark fire lanes with 6-inch red curbing or stripe. Stencil "FIRE LANE NO PARKING" at 25-30 foot intervals. Minimum 20-foot width for fire apparatus access.'],
                    ['title' => 'Directional Markings', 'description' => 'Paint arrows, stop bars, crosswalks, and speed bump markings. Apply text stencils for RESERVED, COMPACT, VISITOR, and unit numbers.'],
                    ['title' => 'Signage Installation', 'description' => 'Install ADA-compliant signs at each accessible space (bottom at least 60 inches above grade). Install fire lane signs every 50 feet. Include state-mandated fine amounts.'],
                    ['title' => 'Glass Bead Application', 'description' => 'For enhanced nighttime visibility, broadcast reflective glass beads into wet paint on crosswalks, fire lanes, and accessible spaces.'],
                    ['title' => 'Inspection and Documentation', 'description' => 'Measure all accessible spaces and aisles. Photograph all work. Provide ADA compliance checklist and documentation for client records.'],
                ],
                'tips' => [
                    ['title' => 'Altitude UV destroys markings faster', 'description' => 'Denver\'s UV fades markings 30-40% faster. Water-based paint may only last 12-15 months. Specify UV-resistant formulations or budget for annual touch-up. Thermoplastic lasts 5-8 years even in Denver.'],
                    ['title' => 'Cold morning/hot afternoon problem', 'description' => 'Traffic paint on cold asphalt below 50 degrees F will not adhere. Wait until pavement reaches 50+ degrees F (usually 10-11 AM in spring/fall). Asphalt above 130 degrees F causes paint to dry too fast.'],
                    ['title' => 'Snow plow damage budget', 'description' => 'Denver receives 50+ inches of snow annually. Plows scrape markings every winter. Budget for annual spring touch-up. Thermoplastic survives plowing better than paint.'],
                    ['title' => 'Colorado ADA enforcement is active', 'description' => 'Fines can reach $150,000 for repeat violations. Ensure signage includes state-mandated fine amounts and that accessible routes to building entrances are maintained.'],
                ],
                'cost_factors' => [
                    'Lot size and total number of spaces',
                    'Existing condition -- re-stripe vs. new layout',
                    'Paint type -- water-based vs. solvent-based vs. thermoplastic',
                    'Number of accessible spaces and ADA layout complexity',
                    'Fire lane linear footage and stenciling requirements',
                    'Signage quantity and type -- new posts vs. existing infrastructure',
                    'Layout changes -- reconfiguring spaces, adding traffic flow',
                ],
                'faqs' => [
                    ['q' => 'How often should a parking lot be re-striped?', 'a' => 'In Denver, every 12-18 months with water-based paint, 18-24 months with solvent-based. High-traffic lots may need annual striping. Thermoplastic lasts 5-8 years. Always re-stripe within 24-48 hours after sealcoating.'],
                    ['q' => 'What are the penalties for non-compliant ADA parking?', 'a' => 'Under the ADA, businesses face $75,000 for a first offense and $150,000 for subsequent violations. Private lawsuits can also be filed. Common violations: incorrect number of spaces, missing van-accessible, narrow access aisles, missing or non-compliant signage.'],
                    ['q' => 'Can you stripe a lot that was just sealcoated?', 'a' => 'Yes, but sealcoat must cure 24-48 hours minimum. In cooler weather, allow 48-72 hours. Striping over uncured sealcoat will peel. We coordinate with sealcoating contractors to schedule within the same closure window.'],
                    ['q' => 'What is the difference between paint and thermoplastic markings?', 'a' => 'Water-based paint is economical, fast-drying, and lasts 12-24 months. Thermoplastic is applied hot, contains reflective beads, and lasts 5-8 years. Thermoplastic costs 3-4 times more initially but is more cost-effective long-term for high-traffic areas.'],
                ],
            ],

            'pressure-washing' => [
                'title' => 'Pressure Washing & Surface Prep',
                'category' => 'Specialty',
                'tagline' => 'Professional pressure washing for driveways, siding, decks, and commercial surfaces',
                'meta_description' => 'Professional pressure washing in Denver. Driveways, siding, decks, concrete, and commercial surfaces cleaned with the right PSI for every material. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Pressure washing removes years of dirt, mildew, algae, oil stains, and oxidation from virtually any exterior surface. It is also the essential first step before any exterior painting, staining, or sealing project. The key is using the right pressure, nozzle, and technique for each surface -- too much pressure damages wood, stucco, and mortar; too little leaves surfaces dirty.

Denver\'s dry climate means less mildew overall compared to humid regions, but heavy growth concentrates on north-facing surfaces and shaded areas. Sprinkler overspray zones are the biggest mildew contributor in Denver. Intense UV causes oxidation buildup on siding and fading on painted surfaces.

Professional pressure washing uses a combination of high-pressure mechanical cleaning and low-pressure chemical cleaning (soft washing). Soft washing is essential for delicate surfaces like stucco, EIFS, painted wood, and roofing. Most jobs require both methods on different surfaces of the same property.',
                'materials' => [
                    'Equipment' => [
                        'Commercial gas pressure washer (3,000-4,000 PSI, 3.5-8 GPM)',
                        'Surface cleaner attachment (16-24 inch spinning bar) for flat concrete',
                        'Extension wands (12-24 ft telescoping) for multi-story reach',
                        'Assorted spray nozzles (0, 15, 25, 40 degree) and downstream injector',
                    ],
                    'Chemicals' => [
                        'Sodium hypochlorite solution with surfactant for mildew/algae',
                        'Concrete degreaser (sodium hydroxide based) for oil stains',
                        'Wood cleaner and brightener (oxalic acid based)',
                        'Efflorescence remover and neutralizing agents',
                    ],
                    'Protection and Safety' => [
                        'Chemical-resistant gloves, boots, and eye protection',
                        'Plastic sheeting for plant and landscaping protection',
                        'Caution/wet floor signage',
                    ],
                    'Wastewater Management' => [
                        'Containment berms and mats for environmental compliance',
                        'Wet/dry vacuum or water reclamation system',
                        'Portable pump for collecting chemical runoff',
                    ],
                ],
                'steps' => [
                    ['title' => 'Property Assessment', 'description' => 'Identify all surfaces and their substrate type (concrete, vinyl, wood, stucco, brick). Document contamination type for each surface. Note delicate surfaces requiring soft washing.'],
                    ['title' => 'Equipment Selection', 'description' => 'Select PSI for each surface: 1,300-1,900 for delicate surfaces, 2,000-2,800 for wood and siding, 2,800-3,500 for concrete, 3,500+ for heavy commercial. Select appropriate nozzles.'],
                    ['title' => 'Pre-Treatment and Protection', 'description' => 'Pre-soak landscaping with clean water. Cover delicate plants. Close all windows and doors. Cover electrical outlets and light fixtures. Move outdoor items away from work areas.'],
                    ['title' => 'Chemical Application', 'description' => 'Apply cleaning solution from bottom to top on vertical surfaces to prevent streaking. Use appropriate chemicals for each contaminant: bleach solution for mildew, degreaser for oil, oxalic acid for rust.'],
                    ['title' => 'Agitation', 'description' => 'Scrub stubborn stains with brushes. Oil stains may need multiple degreaser applications. Use telescoping brush for second-story siding.'],
                    ['title' => 'Hard Surface Washing', 'description' => 'Use surface cleaner attachment on flat concrete for uniform results. Maintain 12-18 inch distance. Work in sections, rinsing downhill. Use appropriate pressure for brick and stone (1,500-2,500 PSI).'],
                    ['title' => 'Siding Washing', 'description' => 'Use 1,200-1,800 PSI for vinyl, 1,000-1,500 for wood and fiber cement, soft wash (under 500 PSI) for stucco. Always spray at a downward angle -- never aim under siding laps.'],
                    ['title' => 'Deck and Fence Washing', 'description' => 'Use 1,500-2,000 PSI max on softwood, up to 2,500 on hardwood. Always wash with wood grain. For deck prep before staining, apply wood brightener after cleaning.'],
                    ['title' => 'Rinse and Neutralize', 'description' => 'Rinse all surfaces top to bottom. Neutralize acid-treated areas. Re-rinse landscaping. Verify all soap residue is removed -- dried soap streaks are a common callback issue.'],
                    ['title' => 'Post-Wash Inspection', 'description' => 'Inspect for remaining stains, missed areas, and any damage. Re-treat stubborn areas. Verify window seals are dry and intact.'],
                    ['title' => 'Wastewater Management', 'description' => 'Contain and manage wastewater per Denver regulations. Chemical runoff cannot be discharged into storm drains. Collect for proper disposal.'],
                    ['title' => 'Drying and Follow-Up', 'description' => 'Allow 24-48 hours drying before painting, staining, or sealing. Inspect post-drying for issues not visible when wet. Provide recommendations for sealing or painting.'],
                ],
                'tips' => [
                    ['title' => 'Denver\'s hard water leaves spots', 'description' => 'Denver\'s moderately hard water (120-180 ppm) leaves mineral deposits on glass and dark surfaces. For final rinses on windows and high-end surfaces, use a water softener filter or deionized water rinse.'],
                    ['title' => 'Freeze risk in shoulder seasons', 'description' => 'Denver can freeze overnight as late as mid-May and as early as September. Water trapped in wood or stucco that freezes causes damage. Schedule so surfaces have full daylight to dry before temperatures drop below 35 degrees F.'],
                    ['title' => 'Mildew concentrates on north faces and sprinkler zones', 'description' => 'Denver\'s dry climate means less overall mildew, but growth concentrates on north-facing surfaces and sprinkler overspray zones. Recommend adjusting sprinkler heads away from siding and fences to prevent rapid regrowth.'],
                    ['title' => 'Altitude reduces pump performance', 'description' => 'Gas pressure washers lose approximately 3% power per 1,000 feet. At Denver altitude, expect 15-16% reduction. A 4,000 PSI unit may deliver only 3,400 PSI. Size equipment one class higher than sea level.'],
                ],
                'cost_factors' => [
                    'Total surface area to be cleaned',
                    'Contamination level -- light annual cleaning vs. heavy mildew/oil remediation',
                    'Surface type complexity -- single substrate vs. mixed requiring different PSI',
                    'Chemical treatment requirements',
                    'Access and logistics -- ground-level vs. multi-story requiring extension wands',
                    'Wastewater containment requirements for commercial jobs',
                    'Pre-paint prep scope -- cleaning only vs. cleaning + brightening + sanding',
                ],
                'faqs' => [
                    ['q' => 'Will pressure washing damage my surfaces?', 'a' => 'Not when done by a professional using correct pressure and technique for each surface. We adjust PSI for every material: 1,000-1,500 for delicate surfaces, 2,000-2,500 for wood, 3,000+ only for hard concrete. We use soft wash for stucco and painted surfaces.'],
                    ['q' => 'How often should I pressure wash?', 'a' => 'Most Denver homes benefit from annual washing. Commercial properties with heavy traffic should be washed 2-3 times per year. Decks should be washed annually before resealing. Denver\'s UV causes oxidation buildup and spring pollen is significant.'],
                    ['q' => 'What is the difference between pressure washing and soft washing?', 'a' => 'Pressure washing uses high water pressure (2,000-4,000 PSI) for hard surfaces. Soft washing uses low pressure (100-500 PSI) with cleaning solutions for delicate surfaces like stucco, painted wood, and roofing. Many jobs use both methods on different surfaces.'],
                    ['q' => 'Will it remove paint or stain from my deck?', 'a' => 'Sound, well-adhered coatings withstand proper pressure washing. Loose or failing coatings will be removed -- which is desirable if you plan to re-stain. We discuss expected outcomes before starting and adjust technique based on your goals.'],
                ],
            ],

            'color-consultation' => [
                'title' => 'Color Consultation & Design',
                'category' => 'Specialty',
                'tagline' => 'Expert color selection tailored to Denver\'s unique light and your home\'s architecture',
                'meta_description' => 'Professional color consultation in Denver. Expert palette development for interiors and exteriors, accounting for Denver\'s unique altitude light conditions. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Choosing paint colors seems simple until you realize that color is entirely dependent on light -- and Denver\'s light is unlike anywhere else. At 5,280 feet with 300+ sunny days per year, colors appear 10-20% more vibrant and saturated than they look on a paint chip under store lighting. The thinner atmosphere filters less blue light, making the sky deeper blue and affecting how every color on your home is perceived against it.

Professional color consultation goes far beyond picking favorites from a fan deck. It involves analyzing undertones of fixed elements (flooring, countertops, cabinetry), understanding how light changes throughout the day in each room, and creating a cohesive palette that flows naturally through connected spaces.

The most common DIY color mistake is choosing a color with an incompatible undertone -- a "warm gray" on walls with cool-toned flooring, or a white trim that clashes with the existing stone. A consultant identifies these conflicts before you commit to painting entire rooms in a color that looks wrong at full scale.',
                'materials' => [
                    'Color Tools' => [
                        'Fan decks from Benjamin Moore, Sherwin-Williams, PPG, and Behr',
                        'Large color sample boards and peel-and-stick samples',
                        'Sample quarts/pints of recommended colors',
                        'Color wheel and undertone identification guide',
                    ],
                    'Assessment Tools' => [
                        'Portable light meter for measuring lux and color temperature',
                        'Digital camera for documentation',
                        'Room measurement tools for paint quantity estimates',
                        'Compass for determining window orientation (N/S/E/W)',
                    ],
                    'Digital Tools' => [
                        'Benjamin Moore Color Portfolio app',
                        'Sherwin-Williams ColorSnap Visualizer',
                        'Room visualization software for digital mockups',
                        'AI color visualizer for realistic previews',
                    ],
                    'Presentation' => [
                        'Foam core boards with color samples',
                        'Printed color specification sheets',
                        'Digital PDF deliverable with room-by-room assignments',
                    ],
                ],
                'steps' => [
                    ['title' => 'Client Intake and Style Discovery', 'description' => 'Understand preferences, lifestyle, and goals. Gather information on style, color likes/dislikes, desired mood for each space, and fixed elements that cannot change.'],
                    ['title' => 'On-Site Assessment', 'description' => 'Evaluate every room analyzing natural light direction, artificial lighting, ceiling height, open floor plan flow, and existing fixed colors in flooring, countertops, and cabinetry.'],
                    ['title' => 'Undertone Analysis', 'description' => 'Identify the undertones of all fixed elements. Every surface has a warm (yellow, orange, gold) or cool (blue, green, purple) undertone. Selected paint colors must share compatible undertones.'],
                    ['title' => 'Color Theory Application', 'description' => 'Apply monochromatic, analogous, or complementary schemes as appropriate. Use the 60-30-10 rule: 60% dominant color (walls), 30% secondary (trim, furniture), 10% accent (doors, accessories).'],
                    ['title' => 'Palette Development', 'description' => 'Create curated palette of 3-5 colors per space: wall color, trim color, ceiling color, accent/feature color, and door color. Specify exact brand, product, color code, and sheen for every recommendation.'],
                    ['title' => 'Large-Scale Sampling', 'description' => 'Paint minimum 12x12 inch samples (ideally 24x24) on actual walls. Place on multiple walls -- color looks dramatically different on a sunlit wall vs. shaded wall. Live with samples for 24-48 hours.'],
                    ['title' => 'Light Testing and Adjustment', 'description' => 'Evaluate samples under all lighting: morning, midday, afternoon, and evening artificial light. A color perfect at noon may look pink under warm evening LED. Adjust based on observations.'],
                    ['title' => 'Whole-Home Flow Planning', 'description' => 'Map how colors transition from room to room. Open floor plans need same color family. Create a "color thread" -- consistent undertone running through all selections. Address sightlines between rooms.'],
                    ['title' => 'Exterior Coordination', 'description' => 'For exteriors: coordinate body, trim, accent, and front door colors with existing roof, stone, and brick. Consider neighborhood context and Denver\'s intense sunlight.'],
                    ['title' => 'Final Presentation', 'description' => 'Deliver final specification document with room-by-room assignments, exact paint codes, sheen recommendations, number of coats needed, and maintenance tips for color longevity.'],
                ],
                'tips' => [
                    ['title' => 'Denver sunlight intensifies everything', 'description' => 'Colors appear 10-20% more vibrant at altitude than under store lighting. Always sample on the actual surface in direct Colorado sunlight. Lean toward muted, desaturated tones for exteriors.'],
                    ['title' => 'North-south light differential is extreme', 'description' => 'North rooms receive cool blue light and can handle warmer colors. South rooms get warm golden light -- cool greens and grays work well to balance. This differential is more pronounced in Denver than lower-altitude cities.'],
                    ['title' => 'Earth tones work best for exteriors', 'description' => 'Denver\'s natural landscape (gold grasses, red sandstone, pine greens, blue sky) provides ideal context for earth-tone palettes. Bright whites can be harsh and glaring. Consider warm whites like cream or Swiss Coffee.'],
                    ['title' => 'Altitude affects color perception', 'description' => 'The thinner atmosphere means exterior colors with blue undertones can look cooler than intended against Denver\'s intense blue sky. Warm-undertone colors maintain better balance.'],
                    ['title' => 'Reflective snow consideration', 'description' => 'Denver averages 50+ inches of snow. Dark exterior colors pop dramatically against snow. Light earth tones can appear washed out. Interior south-facing rooms get reflected snow light in winter, making them brighter and cooler.'],
                ],
                'cost_factors' => [
                    'Scope of consultation -- single room vs. whole-home vs. interior + exterior',
                    'Complexity -- simple repaint in similar tones vs. complete color overhaul',
                    'Number of fixed elements to coordinate with',
                    'Level of deliverable -- verbal recommendations vs. written specs vs. digital renderings',
                    'Sample costs -- sample quarts and large-scale color boards',
                    'Commercial vs. residential -- commercial requires brand integration and stakeholder approval',
                ],
                'faqs' => [
                    ['q' => 'How is professional consultation different from picking colors myself?', 'a' => 'A consultant understands undertones, color theory, and how Denver\'s unique light affects hues -- nuances invisible to most people. The most common DIY mistake is choosing a color with incompatible undertone to fixed elements. A consultant identifies conflicts before you paint entire rooms in the wrong color.'],
                    ['q' => 'How many colors do I need for my home?', 'a' => 'A typical interior uses 3-5 colors: main neutral for common areas, 1-2 secondary colors for bedrooms, an optional accent, and consistent trim/ceiling color. Exteriors use 3: body, trim, and accent. The key is a cohesive palette with intentional variation.'],
                    ['q' => 'Why do colors look different on my wall than in the store?', 'a' => 'Paint color depends entirely on light. Store lighting makes colors appear differently than your home\'s lighting. North-facing rooms receive cool blue light; south-facing get warm golden light. The only way to see true color is sampling on your actual wall at different times of day.'],
                    ['q' => 'What are undertones and why do they matter?', 'a' => 'Every color has a base hue and a subtle undertone beneath it. A gray with blue undertone feels cool and modern; with purple undertone, moody; with green, natural. When undertones clash with fixed elements, something feels "off." Matching undertones is the most important factor in a successful color scheme.'],
                ],
            ],

            'stucco-coating' => [
                'title' => 'Elastomeric & Stucco Coating',
                'category' => 'Specialty',
                'tagline' => 'Waterproof and protect your stucco with flexible coatings built for Colorado\'s freeze-thaw cycles',
                'meta_description' => 'Elastomeric and stucco coating in Denver. Waterproof coatings that bridge cracks, resist freeze-thaw damage, and protect stucco for 8-15 years. Free estimates from Painting Made Easy Denver.',
                'overview' => 'Elastomeric coatings are the gold standard for protecting stucco in Colorado. Unlike standard exterior paint that applies at 3-4 mils thick with minimal flexibility, elastomeric coating applies at 15-25+ mils thick and stretches 200-360% without rupturing. This thickness and flexibility allows it to bridge cracks up to 1/16 inch and maintain a waterproof membrane that moves with the building.

Denver experiences 100+ freeze-thaw cycles per year. Water enters stucco through cracks, freezes and expands up to 9% in volume, and progressively widens the crack. This is the single biggest reason for stucco failure along the Colorado Front Range. Elastomeric coatings prevent water entry while remaining breathable enough to allow interior moisture to escape.

Colorado\'s Front Range has thousands of stucco-clad communities from the 1990s-2010s building boom. Many used one-coat stucco systems over OSB sheathing -- a system known for moisture problems. Before applying elastomeric, it is critical to verify there is no trapped moisture behind the stucco and to resolve all moisture sources first.',
                'materials' => [
                    'Coatings and Repair' => [
                        'Elastomeric masonry coating (Sherwin-Williams Loxon XP, Behr Elastomeric)',
                        'Alkali-resistant masonry primer',
                        'Elastomeric crack filler and caulk',
                        'Stucco patching compound and finish coat mix',
                    ],
                    'Application Equipment' => [
                        'Heavy-duty airless sprayer capable of thick viscosity',
                        'Heavy-duty roller frames with 1-1/4 inch nap covers',
                        '4-inch masonry brushes for detail work',
                        'Wet and dry film thickness gauges',
                    ],
                    'Repair Tools' => [
                        'Cold chisels and margin trowels for crack prep',
                        'Hawk and finishing trowels for stucco repair',
                        'Stucco texture tools (dash brush, float)',
                        'Grinder with diamond cup wheel for crack preparation',
                    ],
                    'Preparation' => [
                        'Pressure washer (1,500-2,500 PSI for stucco)',
                        'Moisture meter (pin-type or pinless)',
                        'Scaffolding or boom lift for access',
                        'N95 respirators and fall protection equipment',
                    ],
                ],
                'steps' => [
                    ['title' => 'Stucco Condition Assessment', 'description' => 'Classify all cracks by severity: hairline (cosmetic), small (1/16-1/8 inch), medium (1/8-1/4 inch requires investigation), large (over 1/4 inch likely structural). Check for delamination with tap test. Measure moisture content.'],
                    ['title' => 'Moisture Source Identification', 'description' => 'Identify and resolve all moisture sources before coating: failed caulk, damaged flashing, gutter failures, sprinklers hitting stucco, soil against walls. Elastomeric will not fix underlying moisture problems -- it will trap them.'],
                    ['title' => 'Small Crack Repair', 'description' => 'Clean cracks with wire brush. Apply elastomeric crack filler. For cracks 1/16 to 1/4 inch, widen with cold chisel, insert backer rod if deep, and fill with elastomeric caulk. Allow 24-48 hours cure.'],
                    ['title' => 'Large Damage Repair', 'description' => 'Remove all loose stucco to sound substrate. Install new metal lath if needed. Apply scratch coat, brown coat, and finish coat matching existing texture. Allow 28-day cure before coating.'],
                    ['title' => 'Surface Cleaning', 'description' => 'Pressure wash at 1,500-2,500 PSI. Apply bleach solution for mold and mildew. Allow 24-48 hours drying. Stucco must be completely dry (below 12% moisture) before elastomeric application.'],
                    ['title' => 'Masking and Protection', 'description' => 'Mask all windows, doors, trim, and fixtures. Elastomeric is extremely thick and very difficult to remove from unintended surfaces once cured. Protect landscaping and walkways.'],
                    ['title' => 'Priming', 'description' => 'Apply alkali-resistant masonry primer compatible with the elastomeric system. Essential on new repairs, unpainted stucco, and surfaces with efflorescence. Follow manufacturer primer recommendation.'],
                    ['title' => 'First Coat Application', 'description' => 'Apply at 10-12 mils wet film thickness per coat -- significantly heavier than standard paint. Coverage: 50-75 sq ft per gallon (vs. 300-400 for standard paint). Maintain a wet edge.'],
                    ['title' => 'Curing Between Coats', 'description' => 'Allow 4-8 hours cure. In cooler Denver temperatures below 60 degrees F, extend to 8-12 hours. Do not apply if rain is expected within 24 hours.'],
                    ['title' => 'Second Coat Application', 'description' => 'Apply second coat at same thickness. Two coats minimum achieves 15-25 mils dry film thickness for crack-bridging and waterproofing. Some specs require three coats for 25-35 mils.'],
                    ['title' => 'Detail and Transition Work', 'description' => 'Cut in at trim, windows, doors. Coat soffits, stucco returns, and parapet walls. Ensure continuous coverage at all corners and foundation-to-grade transition.'],
                    ['title' => 'Quality Control', 'description' => 'Measure dry film thickness with coating gauge. Verify minimum DFT meets specification. Check for pinholes that compromise waterproofing. Touch up thin areas.'],
                    ['title' => 'Cleanup and Documentation', 'description' => 'Remove masking while coating is still slightly flexible. Clean overspray immediately. Provide product specs, DFT measurements, warranty, and maintenance schedule. Expected service life: 8-15 years.'],
                ],
                'tips' => [
                    ['title' => 'Freeze-thaw is the primary enemy', 'description' => 'Denver experiences 100+ freeze-thaw cycles per year. Water in stucco cracks freezes and expands 9%, progressively widening cracks. Elastomeric with 200%+ elongation bridges these cracks. Standard paint (2-5% elongation) cannot. For Colorado stucco, elastomeric is a necessity.'],
                    ['title' => 'Application temperature window is tight', 'description' => 'Surface temperature must be 50-90 degrees F during application AND for 24 hours after. Denver\'s spring/fall pattern of 65 degrees daytime and 35 overnight is problematic. The reliable window is May 15 through September 30.'],
                    ['title' => 'South-facing stucco gets double punishment', 'description' => 'South-facing walls get maximum UV AND thermal cycling. Surface temperatures can reach 140+ degrees F in summer and drop 100 degrees overnight. Elastomeric flexes with this cycling. Apply to south/west elevations first if budget requires phasing.'],
                    ['title' => 'Vapor permeability is critical', 'description' => 'While elastomeric waterproofs the exterior, it must remain breathable to allow interior moisture to escape. In Colorado, vapor drive is outward in winter. Using non-breathable products or too many coats traps moisture causing hidden damage. Verify product perm rating is 8+.'],
                ],
                'cost_factors' => [
                    'Total stucco surface area per elevation',
                    'Stucco condition and repair scope -- minimal cracking vs. extensive damage',
                    'Number of coats -- 2-coat standard vs. 3-coat premium',
                    'Product specification -- standard vs. high-elongation vs. silicone-enhanced',
                    'Surface preparation intensity -- simple wash vs. heavy treatment',
                    'Building height and access requirements',
                    'Material cost -- elastomeric is 3-5x cost per gallon of standard paint, with 4-6x lower coverage',
                ],
                'faqs' => [
                    ['q' => 'What is the difference between elastomeric coating and regular paint?', 'a' => 'Regular paint applies at 3-4 mils with 2-5% elasticity. Elastomeric applies at 15-25+ mils and stretches 200-360%. This bridges cracks up to 1/16 inch and creates a waterproof membrane. It has 10-20x the film build, providing superior protection against moisture, UV, and physical damage.'],
                    ['q' => 'How long does elastomeric coating last in Denver?', 'a' => 'A properly applied two-coat system lasts 8-15 years. South and west walls on the shorter end. By comparison, standard acrylic on stucco lasts 4-7 years in Denver. The long-term cost per year is often lower despite higher upfront cost.'],
                    ['q' => 'Can it be applied over existing paint?', 'a' => 'Yes, if existing paint is sound and well-adhered. Loose, peeling, or chalky paint must be removed first. If multiple old layers exist (3+ coats), consider stripping to bare stucco for best results.'],
                    ['q' => 'Will it hide my stucco texture?', 'a' => 'Elastomeric will soften very fine texture details slightly due to its thickness, but will not hide medium or heavy stucco textures (dash, lace, sand). The heavier the existing texture, the less noticeable the coating thickness.'],
                    ['q' => 'Is it worth the extra cost for Colorado stucco?', 'a' => 'For most Front Range stucco homes, yes. Standard paint costs less upfront but needs reapplication every 4-7 years. Elastomeric costs 2-3x more but lasts 8-15 years. Over 15 years, elastomeric is comparable or cheaper per year. More importantly, it prevents moisture intrusion during freeze-thaw cycles.'],
                ],
            ],
        ];
    }
}

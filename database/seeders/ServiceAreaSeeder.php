<?php

namespace Database\Seeders;

use App\Models\ServiceArea;
use Illuminate\Database\Seeder;

class ServiceAreaSeeder extends Seeder
{
    public function run(): void
    {
        $areas = [
            [
                'city' => 'Denver',
                'slug' => 'denver-co',
                'zip_codes' => ['80201', '80202', '80203', '80204', '80205', '80206', '80207', '80209', '80210', '80211', '80212', '80216', '80218', '80219', '80220', '80222', '80223', '80224', '80230', '80231', '80236', '80237', '80238', '80239', '80246', '80247', '80249'],
                'hero_text' => 'Denver is our home base. From Capitol Hill to Park Hill, we know every neighborhood and deliver exceptional painting services with local expertise.',
                'specialties_text' => 'Interior repaints, historic home restoration, Victorian trim work, commercial spaces',
            ],
            [
                'city' => 'Aurora',
                'slug' => 'aurora-co',
                'zip_codes' => ['80010', '80011', '80012', '80013', '80014', '80015', '80016', '80017', '80018', '80019', '80040', '80041', '80042', '80044', '80045', '80046', '80047'],
                'hero_text' => 'Aurora homeowners trust us to bring their vision to life with meticulous prep, premium paints, and a process that keeps you informed every step of the way.',
                'specialties_text' => 'Stucco exteriors, large home interiors, HOA-compliant color schemes',
            ],
            [
                'city' => 'Lakewood',
                'slug' => 'lakewood-co',
                'zip_codes' => ['80214', '80215', '80226', '80227', '80228', '80232', '80235'],
                'hero_text' => 'From Green Mountain to Belmar, Lakewood homeowners count on us for stress-free painting with real-time updates and guaranteed satisfaction.',
                'specialties_text' => 'Ranch-style home exteriors, basement finishing, deck staining',
            ],
            [
                'city' => 'Arvada',
                'slug' => 'arvada-co',
                'zip_codes' => ['80002', '80003', '80004', '80005', '80006', '80007'],
                'hero_text' => 'Arvada families love our attention to detail. From Olde Town to Leyden Rock, we bring clean, professional painting with zero hassle.',
                'specialties_text' => 'New construction touch-ups, cabinet refinishing, fence staining',
            ],
            [
                'city' => 'Westminster',
                'slug' => 'westminster-co',
                'zip_codes' => ['80021', '80030', '80031', '80234', '80260'],
                'hero_text' => 'Westminster homeowners deserve a painting company that respects their time and their home. That\'s exactly what we deliver.',
                'specialties_text' => 'Townhome interiors, garage floor coatings, commercial offices',
            ],
            [
                'city' => 'Thornton',
                'slug' => 'thornton-co',
                'zip_codes' => ['80229', '80233', '80241', '80260', '80602'],
                'hero_text' => 'Thornton residents get white-glove painting service with daily photo updates and a cleanliness guarantee on every project.',
                'specialties_text' => 'New development touch-ups, accent walls, full exterior repaints',
            ],
            [
                'city' => 'Centennial',
                'slug' => 'centennial-co',
                'zip_codes' => ['80015', '80016', '80111', '80112', '80121', '80122'],
                'hero_text' => 'Centennial homeowners enjoy our premium painting experience with transparent pricing and real-time project tracking.',
                'specialties_text' => 'Luxury home interiors, multi-story exteriors, decorative finishes',
            ],
            [
                'city' => 'Boulder',
                'slug' => 'boulder-co',
                'zip_codes' => ['80301', '80302', '80303', '80304', '80305', '80306', '80309', '80310'],
                'hero_text' => 'Boulder\'s unique architecture deserves a painting team that understands both modern and historic homes. We bring expertise and eco-friendly options.',
                'specialties_text' => 'Eco-friendly paints, historic preservation, natural wood finishes',
            ],
            [
                'city' => 'Broomfield',
                'slug' => 'broomfield-co',
                'zip_codes' => ['80020', '80021', '80023', '80038'],
                'hero_text' => 'Our Broomfield team specializes in the neighborhood styles you see throughout the community, from Broadlands to McKay Landing.',
                'specialties_text' => 'Stucco repairs, exterior color consultation, HOA-approved palettes',
            ],
            [
                'city' => 'Longmont',
                'slug' => 'longmont-co',
                'zip_codes' => ['80501', '80502', '80503', '80504'],
                'hero_text' => 'Longmont homeowners enjoy our combination of small-town service and professional-grade results on every project.',
                'specialties_text' => 'Farmhouse restoration, barn painting, large property exteriors',
            ],
            [
                'city' => 'Castle Rock',
                'slug' => 'castle-rock-co',
                'zip_codes' => ['80104', '80108', '80109'],
                'hero_text' => 'Castle Rock\'s beautiful homes deserve beautiful paint jobs. Our team brings precision, premium products, and total transparency.',
                'specialties_text' => 'Mountain-style homes, stone accent walls, large exterior projects',
            ],
            [
                'city' => 'Parker',
                'slug' => 'parker-co',
                'zip_codes' => ['80134', '80138'],
                'hero_text' => 'Parker families trust us for quality painting that stands up to Colorado\'s weather while making their homes shine.',
                'specialties_text' => 'Newer home repaints, basement finishing, garage painting',
            ],
            [
                'city' => 'Littleton',
                'slug' => 'littleton-co',
                'zip_codes' => ['80120', '80121', '80123', '80124', '80125', '80126', '80127', '80128', '80129', '80130'],
                'hero_text' => 'From historic downtown Littleton to Highlands Ranch, we serve the entire area with professional painting and genuine care.',
                'specialties_text' => 'Victorian trim, deck restoration, full home interior repaints',
            ],
            [
                'city' => 'Englewood',
                'slug' => 'englewood-co',
                'zip_codes' => ['80110', '80111', '80112', '80113'],
                'hero_text' => 'Englewood homeowners love our efficient, clean painting process. We get in, do it right, and leave your home better than we found it.',
                'specialties_text' => 'Bungalow exteriors, condo interiors, commercial retail spaces',
            ],
            [
                'city' => 'Golden',
                'slug' => 'golden-co',
                'zip_codes' => ['80401', '80402', '80403'],
                'hero_text' => 'Golden\'s mountain-adjacent homes face unique weather challenges. Our team uses premium products designed for Colorado\'s climate.',
                'specialties_text' => 'Weather-resistant exteriors, cabin-style finishes, stone accents',
            ],
            [
                'city' => 'Commerce City',
                'slug' => 'commerce-city-co',
                'zip_codes' => ['80022', '80037', '80602'],
                'hero_text' => 'Commerce City is growing fast and we\'re proud to help homeowners in every new and established neighborhood look their best.',
                'specialties_text' => 'New construction, whole-home interiors, garage epoxy',
            ],
            [
                'city' => 'Brighton',
                'slug' => 'brighton-co',
                'zip_codes' => ['80601', '80602', '80603'],
                'hero_text' => 'Brighton families get the same premium painting experience as our Denver clients, with the personal touch of a local team.',
                'specialties_text' => 'Ranch homes, fence and deck staining, barn conversions',
            ],
            [
                'city' => 'Northglenn',
                'slug' => 'northglenn-co',
                'zip_codes' => ['80233', '80234', '80241', '80260'],
                'hero_text' => 'Northglenn homeowners appreciate our transparent pricing and the real-time tracking that takes the guesswork out of painting.',
                'specialties_text' => 'Split-level homes, popcorn ceiling removal, accent walls',
            ],
            [
                'city' => 'Federal Heights',
                'slug' => 'federal-heights-co',
                'zip_codes' => ['80221', '80260'],
                'hero_text' => 'Federal Heights residents get professional painting services backed by our triple guarantee: satisfaction, cleanliness, and communication.',
                'specialties_text' => 'Affordable whole-home repaints, rental property refreshes',
            ],
            [
                'city' => 'Wheat Ridge',
                'slug' => 'wheat-ridge-co',
                'zip_codes' => ['80033', '80034', '80212', '80214', '80215'],
                'hero_text' => 'Wheat Ridge\'s charming homes are our specialty. From mid-century ranches to modern remodels, we bring every home to life.',
                'specialties_text' => 'Mid-century modern homes, kitchen cabinet painting, exterior trim',
            ],
        ];

        foreach ($areas as $area) {
            ServiceArea::updateOrCreate(
                ['slug' => $area['slug']],
                array_merge($area, ['state' => 'CO', 'is_active' => true])
            );
        }
    }
}

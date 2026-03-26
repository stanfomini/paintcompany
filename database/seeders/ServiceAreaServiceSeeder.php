<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\ServiceArea;
use Illuminate\Database\Seeder;

class ServiceAreaServiceSeeder extends Seeder
{
    public function run(): void
    {
        $areas = ServiceArea::active()->orderBy('city')->get();
        $services = Service::active()->orderBy('sort_order')->get();

        if ($areas->isEmpty() || $services->isEmpty()) {
            $this->command->warn('No active service areas or services found. Run ServiceAreaSeeder and ServiceSeeder first.');

            return;
        }

        $coreResidentialSlugs = [
            'interior-painting',
            'exterior-painting',
            'cabinet-painting',
            'deck-fence-staining',
            'drywall-repair',
            'trim-door-painting',
        ];

        $attached = 0;

        foreach ($areas as $area) {
            foreach ($services as $service) {
                $isFeatured = in_array($service->slug, $coreResidentialSlugs);
                $customIntro = $isFeatured ? $this->generateIntro($service, $area) : null;

                $area->services()->syncWithoutDetaching([
                    $service->id => [
                        'custom_intro' => $customIntro,
                        'is_featured' => $isFeatured,
                        'sort_order' => $service->sort_order,
                    ],
                ]);

                $attached++;
            }
        }

        $this->command->info("Attached {$attached} area-service combinations ({$areas->count()} areas x {$services->count()} services).");
    }

    private function generateIntro(Service $service, ServiceArea $area): string
    {
        $city = $area->city;
        $neighborhoods = $area->local_content['neighborhoods'] ?? [];
        $neighborhoodPhrase = ! empty($neighborhoods)
            ? 'from '.implode(' to ', array_slice($neighborhoods, 0, 2))
            : 'across the area';

        return match ($service->slug) {
            'interior-painting' => "Looking for professional interior painters in {$city}, Colorado? Our team serves homeowners {$neighborhoodPhrase}, delivering fresh, clean results with premium paints chosen for Colorado's dry climate. Every project includes thorough prep, real-time tracking, and a satisfaction guarantee.",

            'exterior-painting' => "Protect your {$city} home from Colorado's intense UV, hail, and temperature swings with a professional exterior paint job. We serve homeowners {$neighborhoodPhrase}, using weather-rated coatings and meticulous surface prep to ensure your paint lasts 7-10 years.",

            'cabinet-painting' => "Transform your {$city} kitchen without the cost of a full remodel. Our cabinet painting service gives homeowners {$neighborhoodPhrase} a factory-smooth finish using spray application and premium cabinet-grade coatings that stand up to daily use.",

            'deck-fence-staining' => "Keep your {$city} deck and fence looking great despite Colorado's harsh sun and dry winters. We serve homeowners {$neighborhoodPhrase} with expert staining that penetrates deep, resists UV damage, and extends the life of your outdoor wood.",

            'drywall-repair' => "From nail pops to water damage, our {$city} drywall repair service restores your walls to smooth, paint-ready condition. We match existing textures for homeowners {$neighborhoodPhrase} so repairs are invisible under the final coat.",

            'trim-door-painting' => "Crisp, clean trim and doors make the biggest visual difference in any {$city} home. Our detail-focused painters serve homeowners {$neighborhoodPhrase}, delivering smooth satin and semi-gloss finishes on baseboards, crown molding, doors, and window casings.",

            default => "Professional {$service->title} services for {$city}, Colorado homeowners {$neighborhoodPhrase}. Free estimates with real-time project tracking.",
        };
    }
}

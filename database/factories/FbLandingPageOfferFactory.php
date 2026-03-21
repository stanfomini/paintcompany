<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FbLandingPageOffer>
 */
class FbLandingPageOfferFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->sentence(3);

        return [
            'slug' => Str::slug($title),
            'title' => $title,
            'headline' => fake()->sentence(4),
            'subheadline' => fake()->optional(0.7)->sentence(8),
            'offer_bullets' => [
                fake()->sentence(6),
                fake()->sentence(6),
                fake()->sentence(6),
            ],
            'cta_text' => 'Claim Your Free Estimate',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function withVideo(\App\Models\VideoRecording $video): static
    {
        return $this->state(fn (array $attributes) => [
            'video_recording_id' => $video->id,
        ]);
    }

    public function withHeroPhoto(string $path = 'offers/hero.jpg', string $disk = 'public'): static
    {
        return $this->state(fn (array $attributes) => [
            'hero_photo_path' => $path,
            'hero_photo_disk' => $disk,
        ]);
    }

    public function vipPaintPackage(): static
    {
        return $this->state(fn (array $attributes) => [
            'slug' => 'vip-paint-package',
            'title' => 'VIP Paint Package - Facebook Campaign',
            'headline' => 'The VIP Paint Package',
            'subheadline' => 'Everything you need for a stress-free painting experience — plus exclusive savings when you let us feature your home.',
            'offer_bullets' => [
                'Free access to our AI color selection tools (pick your perfect colors before we start)',
                'Options-based estimates (choose what fits your budget — no pressure)',
                'Written project plan before any work begins (know exactly what to expect)',
                'Up to 25% of your project FREE when you let us feature your home (LIMITED OFFER)',
                'All the extras included: furniture moving, floor protection, daily cleanup',
            ],
            'cta_text' => 'Claim Your Free Estimate',
        ]);
    }
}

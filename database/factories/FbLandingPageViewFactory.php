<?php

namespace Database\Factories;

use App\Models\FbLandingPageOffer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FbLandingPageView>
 */
class FbLandingPageViewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fb_landing_page_offer_id' => FbLandingPageOffer::factory(),
            'variant' => fake()->randomElement(['a', 'b']),
            'visitor_id' => (string) Str::uuid(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'referrer' => fake()->optional(0.5)->url(),
            'utm_source' => fake()->optional(0.6)->randomElement(['facebook', 'instagram']),
            'utm_medium' => fake()->optional(0.6)->randomElement(['cpc', 'cpm', 'social']),
            'utm_campaign' => fake()->optional(0.6)->word(),
            'utm_content' => fake()->optional(0.3)->word(),
            'fbclid' => fake()->optional(0.5)->sha1(),
            'converted' => false,
        ];
    }

    public function variantA(): static
    {
        return $this->state(fn (array $attributes) => [
            'variant' => 'a',
        ]);
    }

    public function variantB(): static
    {
        return $this->state(fn (array $attributes) => [
            'variant' => 'b',
        ]);
    }

    public function converted(): static
    {
        return $this->state(fn (array $attributes) => [
            'converted' => true,
            'converted_at' => now(),
            'customer_id' => \App\Models\Customer::factory(),
            'deal_id' => \App\Models\Deal::factory(),
        ]);
    }

    public function forOffer(FbLandingPageOffer $offer): static
    {
        return $this->state(fn (array $attributes) => [
            'fb_landing_page_offer_id' => $offer->id,
        ]);
    }
}

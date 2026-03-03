<?php

namespace Database\Factories;

use App\Models\StudioTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudioTemplate>
 */
class StudioTemplateFactory extends Factory
{
    protected $model = StudioTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement([
                'before_after', 'walkthrough', 'testimonial',
                'estimate_tour', 'team_highlight', 'promo',
            ]),
            'structure_json' => [
                'segments' => [
                    ['type' => 'media', 'label' => 'Opening shot', 'auto_fill' => 'first_photo'],
                    ['type' => 'recording', 'label' => 'Your commentary'],
                    ['type' => 'media', 'label' => 'Closing shot', 'auto_fill' => 'last_photo'],
                ],
                'overlays' => [
                    ['type' => 'text', 'position' => 'bottom', 'auto_fill' => 'customer_name'],
                ],
            ],
            'system_prompt' => 'Create a professional painting company video.',
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function beforeAfter(): static
    {
        return $this->state(fn () => ['category' => 'before_after']);
    }
}

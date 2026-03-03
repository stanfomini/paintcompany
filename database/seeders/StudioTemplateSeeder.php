<?php

namespace Database\Seeders;

use App\Models\StudioTemplate;
use Illuminate\Database\Seeder;

class StudioTemplateSeeder extends Seeder
{
    /**
     * Seed 6 default studio templates for AI-powered video creation.
     */
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Before & After',
                'description' => 'Show the transformation with before and after photos from a job',
                'category' => 'before_after',
                'sort_order' => 1,
                'structure_json' => [
                    'segments' => [
                        ['type' => 'media', 'label' => 'Before photo', 'auto_fill' => 'before_photos', 'duration_ms' => 3000],
                        ['type' => 'recording', 'label' => 'Your commentary', 'duration_ms' => null],
                        ['type' => 'media', 'label' => 'After photo', 'auto_fill' => 'after_photos', 'duration_ms' => 3000],
                    ],
                    'overlays' => [
                        ['type' => 'text', 'content' => 'Before', 'position' => 'top-left', 'segment_index' => 0],
                        ['type' => 'text', 'content' => 'After', 'position' => 'top-left', 'segment_index' => 2],
                        ['type' => 'text', 'auto_fill' => 'company_name', 'position' => 'bottom'],
                    ],
                    'aspect_ratio' => '9:16',
                ],
                'system_prompt' => 'Create a before/after transformation video. Pull before-category photos first, then after-category photos. Add text overlays labeling each section.',
            ],
            [
                'name' => 'Job Walkthrough',
                'description' => 'Walk through a completed job with property photos and your narration',
                'category' => 'walkthrough',
                'sort_order' => 2,
                'structure_json' => [
                    'segments' => [
                        ['type' => 'recording', 'label' => 'Intro (you talking)', 'duration_ms' => null],
                        ['type' => 'media', 'label' => 'Property photos', 'auto_fill' => 'all_photos', 'duration_ms' => 2500],
                        ['type' => 'recording', 'label' => 'Outro (call to action)', 'duration_ms' => null],
                    ],
                    'overlays' => [
                        ['type' => 'text', 'auto_fill' => 'customer_name', 'position' => 'bottom'],
                        ['type' => 'text', 'auto_fill' => 'company_name', 'position' => 'top-right'],
                    ],
                    'aspect_ratio' => '9:16',
                ],
                'system_prompt' => 'Create a job walkthrough video. Show the property from multiple angles. Include the customer name and company branding.',
            ],
            [
                'name' => 'Customer Testimonial',
                'description' => 'Feature a customer review with job photos in the background',
                'category' => 'testimonial',
                'sort_order' => 3,
                'structure_json' => [
                    'segments' => [
                        ['type' => 'recording', 'label' => 'Customer speaking', 'duration_ms' => null, 'pip_background' => 'after_photos'],
                        ['type' => 'media', 'label' => 'Final result photos', 'auto_fill' => 'after_photos', 'duration_ms' => 3000],
                    ],
                    'overlays' => [
                        ['type' => 'text', 'auto_fill' => 'customer_name', 'position' => 'bottom'],
                        ['type' => 'text', 'auto_fill' => 'company_name', 'position' => 'top-right'],
                    ],
                    'aspect_ratio' => '9:16',
                ],
                'system_prompt' => 'Create a testimonial video. The customer speaks on camera with job photos playing behind them in picture-in-picture mode.',
            ],
            [
                'name' => 'Quick Estimate Tour',
                'description' => 'Walk a prospect through estimate photos with your sales pitch',
                'category' => 'estimate_tour',
                'sort_order' => 4,
                'structure_json' => [
                    'segments' => [
                        ['type' => 'recording', 'label' => 'Intro (greet customer)', 'duration_ms' => null],
                        ['type' => 'media', 'label' => 'Estimate photos', 'auto_fill' => 'estimate_photos', 'duration_ms' => 3000],
                        ['type' => 'recording', 'label' => 'Walkthrough narration', 'duration_ms' => null, 'pip_background' => 'estimate_photos'],
                        ['type' => 'recording', 'label' => 'Close (call to action)', 'duration_ms' => null],
                    ],
                    'overlays' => [
                        ['type' => 'text', 'auto_fill' => 'estimate_total', 'position' => 'bottom'],
                        ['type' => 'text', 'auto_fill' => 'company_name', 'position' => 'top-right'],
                    ],
                    'aspect_ratio' => '9:16',
                ],
                'system_prompt' => 'Create an estimate walkthrough video. Show the property photos from the estimate, narrate the scope of work, and present the price.',
            ],
            [
                'name' => 'Team Highlight',
                'description' => 'Showcase your crew and recent completed work',
                'category' => 'team_highlight',
                'sort_order' => 5,
                'structure_json' => [
                    'segments' => [
                        ['type' => 'recording', 'label' => 'Introduce your team', 'duration_ms' => null],
                        ['type' => 'media', 'label' => 'Recent job photos', 'auto_fill' => 'recent_completions', 'duration_ms' => 2500],
                        ['type' => 'recording', 'label' => 'Why choose us', 'duration_ms' => null],
                    ],
                    'overlays' => [
                        ['type' => 'text', 'auto_fill' => 'company_name', 'position' => 'bottom'],
                    ],
                    'aspect_ratio' => '9:16',
                ],
                'system_prompt' => 'Create a team highlight video. Feature the painting crew and showcase recent completed jobs with before/after comparisons.',
            ],
            [
                'name' => 'Quick Promo',
                'description' => 'Fast promotional clip for social media',
                'category' => 'promo',
                'sort_order' => 6,
                'structure_json' => [
                    'segments' => [
                        ['type' => 'media', 'label' => 'Hero shot', 'auto_fill' => 'best_photo', 'duration_ms' => 2000],
                        ['type' => 'recording', 'label' => 'Your pitch (15 sec max)', 'duration_ms' => null],
                        ['type' => 'media', 'label' => 'Portfolio shots', 'auto_fill' => 'recent_completions', 'duration_ms' => 2000],
                    ],
                    'overlays' => [
                        ['type' => 'text', 'auto_fill' => 'company_name', 'position' => 'center'],
                        ['type' => 'text', 'auto_fill' => 'company_phone', 'position' => 'bottom'],
                    ],
                    'aspect_ratio' => '9:16',
                ],
                'system_prompt' => 'Create a short promotional video for social media. Keep it under 30 seconds. Feature the best work and include contact information.',
            ],
        ];

        foreach ($templates as $template) {
            StudioTemplate::updateOrCreate(
                ['name' => $template['name']],
                $template
            );
        }
    }
}

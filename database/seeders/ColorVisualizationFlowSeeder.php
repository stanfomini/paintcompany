<?php

namespace Database\Seeders;

use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use Illuminate\Database\Seeder;

class ColorVisualizationFlowSeeder extends Seeder
{
    public function run(): void
    {
        $flow = SmsBotFlow::updateOrCreate(
            ['trigger_keyword' => 'COLOR'],
            [
                'name' => 'Color Visualization',
                'status' => 'active',
                'trigger_type' => 'keyword',
                'completion_action' => 'create_lead',
                'timeout_minutes' => 60,
                'business_hours_only' => false,
                'delay_after_lead_minutes' => 0,
            ]
        );

        $steps = [
            [
                'sort_order' => 1,
                'step_type' => SmsBotStep::TYPE_GREETING,
                'message_text' => 'Hey! Welcome to our Color Visualization tool!',
            ],
            [
                'sort_order' => 2,
                'step_type' => SmsBotStep::TYPE_MEDIA_MESSAGE,
                'message_text' => 'Check out this quick tutorial on how to get the best results:',
                // References a ShowcaseMedia slug — resolved at runtime via SmsBotFlowService::resolveMediaUrl()
                'media_url' => 'color-tutorial',
            ],
            [
                'sort_order' => 3,
                'step_type' => SmsBotStep::TYPE_QUESTION,
                'message_text' => 'What room are you looking to paint?',
                'field_mapping' => 'project.rooms',
            ],
            [
                'sort_order' => 4,
                'step_type' => SmsBotStep::TYPE_QUESTION,
                'message_text' => 'Interior, exterior, or both?',
                'field_mapping' => 'project.scope_type',
            ],
            [
                'sort_order' => 5,
                'step_type' => SmsBotStep::TYPE_QUESTION,
                'message_text' => "Now describe your ideal colors! Be specific \u{2014} walls, trim, cabinets, ceiling. Example: 'Walls in Sherwin-Williams Agreeable Gray, white trim, navy blue cabinets'",
            ],
            [
                'sort_order' => 6,
                'step_type' => SmsBotStep::TYPE_PHOTO_PROMPT,
                'message_text' => "Send a photo of the room and we'll bring your vision to life!",
                'wait_for_media' => true,
            ],
            [
                'sort_order' => 7,
                'step_type' => SmsBotStep::TYPE_AI_VISUALIZATION,
                'message_text' => 'Processing your color visualization...',
                // source_step_id set below after steps are created
            ],
            [
                'sort_order' => 8,
                'step_type' => SmsBotStep::TYPE_MESSAGE,
                'message_text' => 'Hope you love it! Ready for a free in-home estimate? {{ booking_link }}',
            ],
        ];

        foreach ($steps as $stepData) {
            SmsBotStep::firstOrCreate(
                [
                    'flow_id' => $flow->id,
                    'sort_order' => $stepData['sort_order'],
                    'step_type' => $stepData['step_type'],
                ],
                array_merge($stepData, ['flow_id' => $flow->id])
            );
        }

        // Link ai_visualization step to the color description question (step 5)
        $colorDescriptionStep = SmsBotStep::where('flow_id', $flow->id)
            ->where('sort_order', 5)
            ->first();

        $aiVisualizationStep = SmsBotStep::where('flow_id', $flow->id)
            ->where('sort_order', 7)
            ->first();

        if ($colorDescriptionStep && $aiVisualizationStep && ! $aiVisualizationStep->source_step_id) {
            $aiVisualizationStep->update(['source_step_id' => $colorDescriptionStep->id]);
        }
    }
}

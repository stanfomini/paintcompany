<?php

namespace Database\Seeders;

use App\Models\SmsBotFlow;
use App\Models\SmsBotStep;
use Illuminate\Database\Seeder;

class SmsBotFlowSeeder extends Seeder
{
    public function run(): void
    {
        $flow = SmsBotFlow::updateOrCreate(
            ['name' => 'Default Lead Engagement'],
            [
                'status' => 'active',
                'trigger_type' => 'new_lead',
                'lead_source_ids' => null,
                'timeout_minutes' => 10080,
                'business_hours_only' => false,
                'delay_after_lead_minutes' => 5,
            ]
        );

        $steps = [
            [
                'sort_order' => 0,
                'step_type' => SmsBotStep::TYPE_GREETING,
                'message_text' => "Hey {name}! \u{1F44B} This is {company}. We're excited about your upcoming project! Want to see your room in a brand new color? Just snap a photo of any wall and text it to us \u{2014} we'll send back a free color visualization in seconds!",
                'quick_reply_options' => null,
                'field_mapping' => null,
                'is_required' => false,
                'delay_seconds' => null,
            ],
            [
                'sort_order' => 1,
                'step_type' => SmsBotStep::TYPE_DELAY,
                'message_text' => '',
                'quick_reply_options' => null,
                'field_mapping' => null,
                'is_required' => false,
                'delay_seconds' => 3,
            ],
            [
                'sort_order' => 2,
                'step_type' => SmsBotStep::TYPE_PHOTO_PROMPT,
                'message_text' => "Whenever you're ready, just snap a quick photo of any room you're thinking about painting and text it right here. We'll show you what it could look like! \u{1F3A8}",
                'quick_reply_options' => null,
                'field_mapping' => null,
                'is_required' => false,
                'delay_seconds' => null,
            ],
            [
                'sort_order' => 3,
                'step_type' => SmsBotStep::TYPE_PHOTO_RECEIVED,
                'message_text' => "Got your photo! \u{1F4F8} Give us just a moment to work some magic...",
                'quick_reply_options' => null,
                'field_mapping' => null,
                'is_required' => false,
                'delay_seconds' => null,
            ],
            [
                'sort_order' => 4,
                'step_type' => SmsBotStep::TYPE_QUESTION,
                'message_text' => 'Love it! What room is this?',
                'quick_reply_options' => null,
                'field_mapping' => 'customer.notes',
                'is_required' => false,
                'delay_seconds' => null,
            ],
            [
                'sort_order' => 5,
                'step_type' => SmsBotStep::TYPE_QUESTION,
                'message_text' => 'How many rooms are you thinking about painting?',
                'quick_reply_options' => null,
                'field_mapping' => 'deal.description',
                'is_required' => false,
                'delay_seconds' => null,
            ],
            [
                'sort_order' => 6,
                'step_type' => SmsBotStep::TYPE_QUESTION,
                'message_text' => "What's your timeline? Are you looking to get this done soon or just exploring?",
                'quick_reply_options' => [
                    ['label' => 'ASAP', 'value' => 'asap'],
                    ['label' => 'Next few weeks', 'value' => 'few_weeks'],
                    ['label' => 'Just exploring', 'value' => 'exploring'],
                ],
                'field_mapping' => null,
                'is_required' => false,
                'delay_seconds' => null,
            ],
            [
                'sort_order' => 7,
                'step_type' => SmsBotStep::TYPE_HANDOFF,
                'message_text' => 'Your estimator {estimator} will be reaching out! In the meantime, check out your full Project Preview: {portal_link}',
                'quick_reply_options' => null,
                'field_mapping' => null,
                'is_required' => false,
                'delay_seconds' => null,
            ],
        ];

        foreach ($steps as $stepData) {
            SmsBotStep::firstOrCreate(
                [
                    'flow_id' => $flow->id,
                    'sort_order' => $stepData['sort_order'],
                    'step_type' => $stepData['step_type'],
                ],
                $stepData
            );
        }
    }
}

<?php

namespace App\Services;

use App\Models\Job;
use App\Models\MarketingCampaignLog;
use App\Models\MarketingStrategy;
use Illuminate\Support\Facades\Log;

class MarketingCampaignService
{
    /**
     * Trigger marketing campaigns when a job is completed.
     * Called inline from JobService::completeJob() matching existing side-effect pattern.
     */
    public function onJobCompleted(Job $job): void
    {
        try {
            $this->handleNeighborhoodCampaign($job);
            $this->handleReferralProgram($job);
            $this->handleReviewRequest($job);
        } catch (\Throwable $e) {
            Log::error('Marketing campaign error on job completion', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function handleNeighborhoodCampaign(Job $job): void
    {
        $strategy = MarketingStrategy::findByKey('neighborhood_campaigns');
        if (! $strategy?->isActive()) {
            return;
        }

        $address = $job->deal?->property?->full_address
            ?? $job->deal?->customer?->full_address
            ?? null;

        if (! $address) {
            return;
        }

        MarketingCampaignLog::create([
            'marketing_strategy_id' => $strategy->id,
            'trigger_type' => 'job_completed',
            'trigger_id' => $job->id,
            'trigger_model' => Job::class,
            'action' => 'campaign_queued',
            'recipient_address' => $address,
            'status' => 'queued',
            'metadata' => [
                'radius_miles' => $strategy->getConfig('radius_miles', 1.0),
                'job_value' => $job->deal?->estimate?->total ?? 0,
            ],
        ]);
    }

    private function handleReferralProgram(Job $job): void
    {
        $strategy = MarketingStrategy::findByKey('referral_program');
        if (! $strategy?->isActive()) {
            return;
        }

        $customer = $job->deal?->customer;
        $phone = $customer?->phone;
        if (! $phone) {
            return;
        }

        $template = $strategy->getConfig('sms_template', 'Thanks for choosing Painting Made Easy! Love your new look? Share this link with a friend — you both get $150.');
        $delayDays = $strategy->getConfig('delay_days_after_completion', 7);

        MarketingCampaignLog::create([
            'marketing_strategy_id' => $strategy->id,
            'trigger_type' => 'job_completed',
            'trigger_id' => $job->id,
            'trigger_model' => Job::class,
            'action' => 'sms_sent',
            'recipient_phone' => $phone,
            'message_body' => $template,
            'status' => 'queued',
            'metadata' => [
                'delay_days' => $delayDays,
                'customer_name' => $customer->full_name ?? '',
                'scheduled_for' => now()->addDays($delayDays)->toDateTimeString(),
            ],
        ]);
    }

    private function handleReviewRequest(Job $job): void
    {
        $strategy = MarketingStrategy::findByKey('review_requests');
        if (! $strategy?->isActive()) {
            return;
        }

        $customer = $job->deal?->customer;
        $phone = $customer?->phone;
        if (! $phone) {
            return;
        }

        $template = $strategy->getConfig('sms_template', 'Hi! Thanks for trusting us with your painting project. Would you share your experience? It helps other Denver homeowners find us.');
        $delayDays = $strategy->getConfig('delay_days_after_completion', 3);

        MarketingCampaignLog::create([
            'marketing_strategy_id' => $strategy->id,
            'trigger_type' => 'job_completed',
            'trigger_id' => $job->id,
            'trigger_model' => Job::class,
            'action' => 'sms_sent',
            'recipient_phone' => $phone,
            'message_body' => $template,
            'status' => 'queued',
            'metadata' => [
                'delay_days' => $delayDays,
                'google_review_url' => $strategy->getConfig('google_review_url'),
                'yelp_review_url' => $strategy->getConfig('yelp_review_url'),
                'customer_name' => $customer->full_name ?? '',
                'scheduled_for' => now()->addDays($delayDays)->toDateTimeString(),
            ],
        ]);
    }
}

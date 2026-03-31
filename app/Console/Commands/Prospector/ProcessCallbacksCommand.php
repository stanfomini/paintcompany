<?php

namespace App\Console\Commands\Prospector;

use App\Events\Prospector\CallbackDue;
use App\Models\Prospector\CampaignContact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ProcessCallbacksCommand extends Command
{
    protected $signature = 'prospector:process-callbacks';

    protected $description = 'Re-queue contacts with due callback times to the front of the dialer queue';

    public function handle(): int
    {
        $dueCallbacks = CampaignContact::callbackScheduled()
            ->whereHas('contact', fn ($q) => $q->where('follow_up_at', '<=', now()))
            ->with(['contact', 'campaign.company'])
            ->get();

        if ($dueCallbacks->isEmpty()) {
            return self::SUCCESS;
        }

        $count = 0;

        foreach ($dueCallbacks as $entry) {
            // Push existing queued items back to make room at front
            CampaignContact::where('campaign_id', $entry->campaign_id)
                ->where('status', 'queued')
                ->increment('sort_order');

            // Move callback to front of queue
            $entry->update(['status' => 'queued', 'sort_order' => 0]);

            // Clear the follow-up time
            $entry->contact->update(['follow_up_at' => null]);

            // Broadcast callback due notification
            if ($entry->campaign?->company) {
                broadcast(new CallbackDue(
                    $entry->contact,
                    $entry->campaign_id,
                    $entry->campaign->company_id,
                ));
            }

            $count++;

            Log::info('Callback re-queued', [
                'contact_id' => $entry->contact_id,
                'campaign_id' => $entry->campaign_id,
            ]);
        }

        $this->info("Re-queued {$count} callback(s).");

        return self::SUCCESS;
    }
}

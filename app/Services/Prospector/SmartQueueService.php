<?php

namespace App\Services\Prospector;

use App\Events\Prospector\QueueItemClaimed;
use App\Models\Prospector\Agent;
use App\Models\Prospector\CampaignContact;
use Illuminate\Support\Collection;

class SmartQueueService
{
    /**
     * Get the unified smart queue for an agent across all assigned companies.
     * Priority items (inbound calls, unread SMS, voicemails) sort first.
     * Excludes items claimed by other agents.
     */
    public function getQueue(
        Agent $agent,
        ?int $companyId = null,
        ?int $campaignId = null,
        ?int $pipelineStageId = null,
        ?string $search = null,
        int $limit = 50,
    ): Collection {
        $companyIds = $companyId ? [$companyId] : $agent->getCompanyIds();

        $query = CampaignContact::query()
            ->where('status', 'queued')
            ->whereHas('campaign', fn ($q) => $q->where('status', 'active')->whereIn('company_id', $companyIds))
            ->where(fn ($q) => $q->whereNull('claimed_by_agent_id')->orWhere('claimed_by_agent_id', $agent->id))
            ->with(['contact.company', 'contact.pipelineStage', 'campaign']);

        if ($campaignId) {
            $query->where('campaign_id', $campaignId);
        }

        if ($pipelineStageId) {
            $query->whereHas('contact', fn ($q) => $q->where('pipeline_stage_id', $pipelineStageId));
        }

        if ($search) {
            $query->whereHas('contact', fn ($q) => $q->search($search));
        }

        return $query
            ->orderByDesc('is_priority')
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();
    }

    /**
     * Get the next queue item for an agent (first unclaimed priority or regular item).
     */
    public function getNext(Agent $agent, ?int $campaignId = null): ?CampaignContact
    {
        $companyIds = $agent->getCompanyIds();

        return CampaignContact::query()
            ->where('status', 'queued')
            ->whereNull('claimed_by_agent_id')
            ->whereHas('campaign', fn ($q) => $q->where('status', 'active')->whereIn('company_id', $companyIds))
            ->when($campaignId, fn ($q) => $q->where('campaign_id', $campaignId))
            ->orderByDesc('is_priority')
            ->orderBy('sort_order')
            ->with(['contact.company', 'campaign'])
            ->first();
    }

    /**
     * Atomically claim a queue item for an agent.
     * Returns false if already claimed by another agent.
     */
    public function claim(CampaignContact $entry, Agent $agent): bool
    {
        $claimed = CampaignContact::where('id', $entry->id)
            ->whereNull('claimed_by_agent_id')
            ->update(['claimed_by_agent_id' => $agent->id]);

        if ($claimed) {
            $entry->refresh();
            broadcast(new QueueItemClaimed(
                campaignContactId: $entry->id,
                contactId: $entry->contact_id,
                claimedByAgentId: $agent->id,
                companyId: $entry->campaign->company_id,
            ));

            return true;
        }

        return false;
    }

    /**
     * Release a claimed queue item back to the pool.
     */
    public function release(CampaignContact $entry): void
    {
        $entry->update(['claimed_by_agent_id' => null]);
    }
}

<?php

namespace App\Services\Prospector;

use App\Events\Prospector\QueueItemClaimed;
use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Contact;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SmartQueueService
{
    /**
     * Get the unified smart queue for an agent — pipeline-driven, prioritized by activity.
     *
     * Priority tiers:
     * 1. Active inbound call (ringing/in_progress)
     * 2. Unread SMS
     * 3. Missed call / voicemail (last 24h)
     * 4. Callback due (follow_up_at <= now)
     * 5. Regular contacts (sorted by staleness)
     */
    public function getQueue(
        Agent $agent,
        ?int $companyId = null,
        ?int $pipelineStageId = null,
        ?string $search = null,
        int $limit = 50,
    ): Collection {
        $companyIds = $companyId ? [$companyId] : $agent->getCompanyIds();

        $query = Contact::query()
            ->whereIn('company_id', $companyIds)
            ->where('do_not_call', false)
            ->whereNotNull('pipeline_stage_id')
            ->where(fn ($q) => $q->whereNull('claimed_by_agent_id')->orWhere('claimed_by_agent_id', $agent->id))
            ->with(['company', 'pipelineStage']);

        if ($pipelineStageId) {
            $query->where('pipeline_stage_id', $pipelineStageId);
        }

        if ($search) {
            $query->search($search);
        }

        $now = now()->toDateTimeString();
        $oneDayAgo = now()->subDay()->toDateTimeString();

        // Compute priority tier as a sortable value using DB-agnostic approach
        $query->selectRaw('prospector_contacts.*, (
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM prospector_calls
                    WHERE prospector_calls.contact_id = prospector_contacts.id
                    AND prospector_calls.direction = ?
                    AND prospector_calls.status IN (?, ?)
                    LIMIT 1
                ) THEN 1
                WHEN prospector_contacts.has_unread_sms = 1 THEN 2
                WHEN EXISTS (
                    SELECT 1 FROM prospector_calls
                    WHERE prospector_calls.contact_id = prospector_contacts.id
                    AND prospector_calls.direction = ?
                    AND prospector_calls.status IN (?, ?, ?)
                    AND prospector_calls.answered_at IS NULL
                    AND prospector_calls.created_at >= ?
                    LIMIT 1
                ) THEN 3
                WHEN prospector_contacts.follow_up_at IS NOT NULL AND prospector_contacts.follow_up_at <= ? THEN 4
                ELSE 5
            END
        ) as queue_priority', [
            'inbound', 'ringing', 'in_progress',
            'inbound', 'no_answer', 'voicemail', 'busy', $oneDayAgo,
            $now,
        ]);

        return $query
            ->orderBy('queue_priority')
            ->orderBy('last_contacted_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get the next contact for an agent (highest priority unclaimed contact).
     */
    public function getNext(Agent $agent): ?Contact
    {
        return $this->getQueue($agent, limit: 1)->first();
    }

    /**
     * Atomically claim a contact for an agent.
     * Returns false if already claimed by another agent.
     */
    public function claim(Contact $contact, Agent $agent): bool
    {
        $claimed = Contact::where('id', $contact->id)
            ->whereNull('claimed_by_agent_id')
            ->update(['claimed_by_agent_id' => $agent->id]);

        if ($claimed) {
            $contact->refresh();
            broadcast(new QueueItemClaimed(
                contactId: $contact->id,
                claimedByAgentId: $agent->id,
                companyId: $contact->company_id,
            ));

            return true;
        }

        return false;
    }

    /**
     * Release a claimed contact back to the pool.
     */
    public function release(Contact $contact): void
    {
        $contact->update(['claimed_by_agent_id' => null]);
    }
}

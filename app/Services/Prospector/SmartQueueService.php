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
     * 2. New lead (created in last 15 min)
     * 3. Unread SMS
     * 4. Missed call / voicemail (last 24h)
     * 5. Callback due (follow_up_at <= now)
     * 6. Regular contacts (sorted by staleness)
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
            ->whereIn('prospector_contacts.company_id', $companyIds)
            ->where('prospector_contacts.do_not_call', false)
            ->whereNotNull('prospector_contacts.pipeline_stage_id')
            ->where(fn ($q) => $q->whereNull('prospector_contacts.claimed_by_agent_id')->orWhere('prospector_contacts.claimed_by_agent_id', $agent->id))
            ->with(['company', 'pipelineStage']);

        if ($pipelineStageId) {
            $query->where('prospector_contacts.pipeline_stage_id', $pipelineStageId);
        }

        if ($search) {
            $query->search($search);
        }

        $now = now()->toDateTimeString();
        $oneDayAgo = now()->subDay()->toDateTimeString();
        $fifteenMinAgo = now()->subMinutes(15)->toDateTimeString();

        // Join pipeline stages to get sort_order for secondary sorting
        $query->leftJoin('prospector_pipeline_stages', 'prospector_contacts.pipeline_stage_id', '=', 'prospector_pipeline_stages.id');

        // Compute priority tier as a sortable value using DB-agnostic approach
        $query->selectRaw('prospector_contacts.*, prospector_pipeline_stages.sort_order as stage_sort_order, (
            CASE
                WHEN EXISTS (
                    SELECT 1 FROM prospector_calls
                    WHERE prospector_calls.contact_id = prospector_contacts.id
                    AND prospector_calls.direction = ?
                    AND prospector_calls.status IN (?, ?)
                    LIMIT 1
                ) THEN 1
                WHEN prospector_contacts.created_at >= ? THEN 2
                WHEN prospector_contacts.has_unread_sms = 1 THEN 3
                WHEN EXISTS (
                    SELECT 1 FROM prospector_calls
                    WHERE prospector_calls.contact_id = prospector_contacts.id
                    AND prospector_calls.direction = ?
                    AND prospector_calls.status IN (?, ?, ?)
                    AND prospector_calls.answered_at IS NULL
                    AND prospector_calls.created_at >= ?
                    LIMIT 1
                ) THEN 4
                WHEN prospector_contacts.follow_up_at IS NOT NULL AND prospector_contacts.follow_up_at <= ? THEN 5
                ELSE 6
            END
        ) as queue_priority', [
            'inbound', 'ringing', 'in_progress',
            $fifteenMinAgo,
            'inbound', 'no_answer', 'voicemail', 'busy', $oneDayAgo,
            $now,
        ]);

        return $query
            ->orderBy('queue_priority')
            ->orderBy('stage_sort_order')
            ->orderBy('prospector_contacts.last_contacted_at')
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

    /**
     * Get the queue as a plain array for JSON serialization to Alpine.js.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getQueueAsArray(
        Agent $agent,
        ?int $companyId = null,
        ?int $pipelineStageId = null,
        ?string $search = null,
        int $limit = 50,
    ): array {
        return $this->getQueue($agent, $companyId, $pipelineStageId, $search, $limit)
            ->map(fn (Contact $c) => [
                'id' => $c->id,
                'name' => $c->getFullName(),
                'phone' => $c->getDisplayPhone(),
                'company_id' => $c->company_id,
                'company_name' => $c->company?->name ?? '',
                'company_color' => $c->company?->color ?? '#6B7280',
                'pipeline_stage_id' => $c->pipeline_stage_id,
                'pipeline_stage_name' => $c->pipelineStage?->name ?? '',
                'stage_sort_order' => (int) ($c->stage_sort_order ?? 99),
                'queue_priority' => (int) $c->queue_priority,
                'has_unread_sms' => (bool) $c->has_unread_sms,
                'follow_up_at' => $c->follow_up_at?->toISOString(),
                'created_at' => $c->created_at?->toISOString(),
                'source' => $c->source,
                'last_contacted_at' => $c->last_contacted_at?->toISOString(),
            ])
            ->values()
            ->all();
    }
}

<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use Illuminate\Support\Facades\Log;

/**
 * CampaignQueueService
 *
 * Responsible for fetching the next callable contact from a campaign queue,
 * applying all call-rule gates:
 *   - Not exhausted (total_attempts, days_in_campaign)
 *   - Not over daily limit (calls_today vs max_calls_per_day)
 *   - Cooldown window respected (next_callable_at)
 *   - TCPA / campaign calling-hours window (via Campaign::isContactCallable)
 *   - Contact not soft-deleted or DNC
 */
class CampaignQueueService
{
    /** Maximum contacts to examine before giving up (prevents infinite loops). */
    private const MAX_SCAN = 100;

    /**
     * Get the next CampaignContact that is ready to be dialed.
     *
     * Returns null when the queue is empty or all remaining contacts
     * are blocked by call rules.
     */
    public function getNext(Campaign $campaign): ?CampaignContact
    {
        $scanned = 0;

        while ($scanned < self::MAX_SCAN) {
            $entry = CampaignContact::where('campaign_id', $campaign->id)
                ->where('status', 'queued')
                ->where('calls_today', '<', $campaign->max_calls_per_day)
                ->where(function ($q) {
                    $q->whereNull('next_callable_at')
                        ->orWhere('next_callable_at', '<=', now());
                })
                ->orderByDesc('is_priority')
                ->orderBy('sort_order')
                ->with('contact')
                ->first();

            if (! $entry) {
                return null;
            }

            $contact = $entry->contact;

            // Skip trashed or missing contacts
            if (! $contact || $contact->trashed()) {
                $entry->markSkipped();
                $scanned++;

                continue;
            }

            // Skip DNC contacts
            if ($contact->do_not_call) {
                $entry->markSkipped();
                $scanned++;

                Log::debug('CampaignQueueService: skipped DNC contact', [
                    'contact_id' => $contact->id,
                    'campaign_id' => $campaign->id,
                ]);

                continue;
            }

            // Exhaustion check (total attempts / days in campaign)
            if ($entry->isExhausted($campaign)) {
                $entry->exhaust();
                $scanned++;

                Log::debug('CampaignQueueService: exhausted contact', [
                    'contact_id' => $contact->id,
                    'campaign_id' => $campaign->id,
                    'total_attempts' => $entry->total_attempts,
                ]);

                continue;
            }

            // TCPA / calling-hours check
            if (! $campaign->isContactCallable($contact)) {
                // Not callable right now — skip this scan cycle but do not exhaust
                $scanned++;

                Log::debug('CampaignQueueService: contact not callable (hours/TCPA)', [
                    'contact_id' => $contact->id,
                    'campaign_id' => $campaign->id,
                    'timezone' => $contact->timezone,
                ]);

                // Move on — return null so the caller retries later rather than
                // burning through the whole queue at the wrong time of day.
                return null;
            }

            return $entry;
        }

        Log::warning('CampaignQueueService: reached max scan limit', [
            'campaign_id' => $campaign->id,
            'scanned' => $scanned,
        ]);

        return null;
    }

    /**
     * Atomically claim a CampaignContact for an agent session.
     * Returns false if already claimed.
     */
    public function claim(CampaignContact $entry, int $agentId): bool
    {
        return CampaignContact::where('id', $entry->id)
            ->whereNull('claimed_by_agent_id')
            ->update(['claimed_by_agent_id' => $agentId]) > 0;
    }

    /**
     * Release a previously claimed entry back to the pool.
     */
    public function release(CampaignContact $entry): void
    {
        $entry->update(['claimed_by_agent_id' => null]);
    }

    /**
     * Count how many contacts are still callable (queued and not exhausted).
     */
    public function countCallable(Campaign $campaign): int
    {
        return CampaignContact::where('campaign_id', $campaign->id)
            ->where('status', 'queued')
            ->where('calls_today', '<', $campaign->max_calls_per_day)
            ->count();
    }

    /**
     * Whether the campaign still has any contacts left to dial today.
     */
    public function hasCallable(Campaign $campaign): bool
    {
        return $this->countCallable($campaign) > 0;
    }

    /**
     * Get queue stats: total queued vs callable right now.
     *
     * "Callable" means passing ALL gates: daily limit, cooldown, DNC,
     * exhaustion, and TCPA/timezone hours.
     *
     * @return array{total: int, callable: int}
     */
    public function getQueueStats(Campaign $campaign): array
    {
        $total = CampaignContact::where('campaign_id', $campaign->id)
            ->where('status', 'queued')
            ->count();

        $candidates = CampaignContact::where('campaign_id', $campaign->id)
            ->where('status', 'queued')
            ->where('calls_today', '<', $campaign->max_calls_per_day)
            ->where(function ($q) {
                $q->whereNull('next_callable_at')
                    ->orWhere('next_callable_at', '<=', now());
            })
            ->with('contact')
            ->get();

        $callable = $candidates->filter(function (CampaignContact $entry) use ($campaign) {
            $contact = $entry->contact;

            return $contact
                && ! $contact->trashed()
                && ! $contact->do_not_call
                && ! $entry->isExhausted($campaign)
                && $campaign->isContactCallable($contact);
        })->count();

        return ['total' => $total, 'callable' => $callable];
    }

    /**
     * Return the queue as a JSON-serializable array for Alpine.js.
     */
    public function getQueueAsArray(Campaign $campaign, $agent, int $limit = 50): array
    {
        $entries = CampaignContact::where('campaign_id', $campaign->id)
            ->where('status', 'queued')
            ->where('calls_today', '<', $campaign->max_calls_per_day)
            ->where(function ($q) {
                $q->whereNull('next_callable_at')
                    ->orWhere('next_callable_at', '<=', now());
            })
            ->orderByDesc('is_priority')
            ->orderBy('sort_order')
            ->with('contact.company')
            ->limit($limit)
            ->get()
            ->filter(function (CampaignContact $entry) use ($campaign) {
                $contact = $entry->contact;

                return $contact
                    && ! $contact->trashed()
                    && ! $contact->do_not_call
                    && ! $entry->isExhausted($campaign)
                    && $campaign->isContactCallable($contact);
            });

        return $entries->map(function (CampaignContact $entry) use ($campaign) {
            $contact = $entry->contact;

            return [
                'id' => $contact->id,
                'campaign_contact_id' => $entry->id,
                'name' => $contact->getFullName(),
                'phone' => $contact->getDisplayPhone(),
                'company_id' => $contact->company_id,
                'company_name' => $contact->company?->name,
                'company_color' => $contact->company?->color,
                'calls_today' => $entry->calls_today,
                'max_calls_today' => $campaign->max_calls_per_day,
                'total_attempts' => $entry->total_attempts,
                'max_total' => $campaign->max_total_attempts,
                'is_priority' => $entry->is_priority,
                'priority_reason' => $entry->priority_reason,
                'follow_up_at' => $contact->follow_up_at?->toISOString(),
            ];
        })->values()->all();
    }
}

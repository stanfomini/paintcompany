<?php

namespace App\Services\Prospector;

use App\Events\Prospector\ContactDispositioned;
use App\Events\Prospector\DialerCallEnded;
use App\Events\Prospector\QueueUpdated;
use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Contact;
use App\Models\Prospector\DialerSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * DialerOrchestrationService
 *
 * Manages the persistent-conference session lifecycle for campaign dialing:
 *
 *   1. startSession()  — create a DialerSession + conference room
 *   2. dialNext()      — pick next contact, create Call, dial into conference
 *   3. handleAnswered()— mark Call answered, update agent state
 *   4. disposition()   — save outcome, handle exhaust/callback, advance queue
 *   5. endSession()    — complete the DialerSession, clean up
 *
 * The conference room persists for the full session so the agent stays connected
 * and each contact is brought in/removed without the agent ever being torn down.
 */
class DialerOrchestrationService
{
    public function __construct(
        private CampaignQueueService $queueService,
        private ConferenceService $conferenceService,
        private NumberSelectionService $numberSelectionService,
        private AgentStateService $agentStateService,
        private AmdService $amdService,
    ) {}

    // ──────────────────────────────────────
    // Session lifecycle
    // ──────────────────────────────────────

    /**
     * Start a new dialer session for an agent on a campaign.
     *
     * Creates the DialerSession, generates a persistent conference room name,
     * and sets the agent to 'available'.
     */
    public function startSession(Agent $agent, Campaign $campaign): DialerSession
    {
        $session = DialerSession::create([
            'agent_id' => $agent->id,
            'campaign_id' => $campaign->id,
            'mode' => 'single',
            'parallel_lines' => 1,
            'status' => 'active',
            'conference_name' => $this->makeConferenceName(),
            'started_at' => now(),
        ]);

        $this->agentStateService->setStatus($agent, 'available');

        Log::info('DialerOrchestration: session started', [
            'session_id' => $session->id,
            'session_ulid' => $session->ulid,
            'agent_id' => $agent->id,
            'campaign_id' => $campaign->id,
            'conference_name' => $session->conference_name,
        ]);

        return $session;
    }

    /**
     * Pause the session (agent on break).
     */
    public function pauseSession(DialerSession $session): void
    {
        $session->update(['status' => 'paused']);
        $this->agentStateService->setStatus($session->agent, 'break');

        Log::info('DialerOrchestration: session paused', [
            'session_id' => $session->id,
        ]);
    }

    /**
     * Resume a paused session.
     */
    public function resumeSession(DialerSession $session): void
    {
        $session->update(['status' => 'active']);
        $this->agentStateService->setStatus($session->agent, 'available');

        Log::info('DialerOrchestration: session resumed', [
            'session_id' => $session->id,
        ]);
    }

    /**
     * End the session gracefully.
     */
    public function endSession(DialerSession $session): void
    {
        if ($session->calls_attempted > 0) {
            $session->complete();
        } else {
            $session->abandon();
        }

        $this->agentStateService->setStatus($session->agent, 'offline');

        Log::info('DialerOrchestration: session ended', [
            'session_id' => $session->id,
            'calls_attempted' => $session->calls_attempted,
            'calls_connected' => $session->calls_connected,
        ]);
    }

    // ──────────────────────────────────────
    // Dial flow
    // ──────────────────────────────────────

    /**
     * Dial the next contact from the campaign queue into the persistent conference.
     *
     * Flow:
     *   1. Fetch next callable CampaignContact (via CampaignQueueService)
     *   2. Claim it atomically
     *   3. Record a Call
     *   4. Dial the contact into the session's persistent conference room
     *   5. Update session stats
     *
     * Returns null when the queue is empty or all contacts are gated.
     */
    public function dialNext(DialerSession $session): ?Call
    {
        if (! $session->isActive()) {
            return null;
        }

        $session->load('agent.company', 'campaign');

        $agent = $session->agent;
        $campaign = $session->campaign;
        $company = $agent->company;

        $entry = $this->queueService->getNext($campaign);

        if (! $entry) {
            Log::info('DialerOrchestration: queue empty', [
                'session_id' => $session->id,
                'campaign_id' => $campaign->id,
            ]);

            return null;
        }

        // Atomically claim
        if (! $this->queueService->claim($entry, $agent->id)) {
            Log::debug('DialerOrchestration: entry claimed by another agent', [
                'campaign_contact_id' => $entry->id,
            ]);

            // Try once more with the next entry
            $entry = $this->queueService->getNext($campaign);

            if (! $entry || ! $this->queueService->claim($entry, $agent->id)) {
                return null;
            }
        }

        $contact = $entry->contact;

        // Select outbound number
        try {
            $fromNumber = $this->numberSelectionService->selectNumber($company, $contact, $campaign);
        } catch (\RuntimeException $e) {
            Log::warning('DialerOrchestration: no number available', [
                'session_id' => $session->id,
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);
            $this->queueService->release($entry);

            return null;
        }

        $conferenceName = $session->conference_name ?? $this->makeConferenceName();

        // Persist conference_name if not already set
        if (! $session->conference_name) {
            $session->update(['conference_name' => $conferenceName]);
        }

        // Mark as dialing and record attempt
        $entry->markDialing();
        $entry->recordCallAttempt($campaign);

        // Create Call record
        $call = Call::create([
            'company_id' => $company->id,
            'dialer_session_id' => $session->id,
            'agent_id' => $agent->id,
            'contact_id' => $contact->id,
            'campaign_id' => $campaign->id,
            'direction' => 'outbound',
            'from_number' => $fromNumber->phone_number,
            'from_phone_number_id' => $fromNumber->id,
            'to_number' => $contact->phone,
            'status' => 'ringing',
            'twilio_conference_sid' => $conferenceName,
            'started_at' => now(),
        ]);

        // Dial into persistent conference
        try {
            $amdParams = config('prospector.defaults.amd_enabled')
                ? $this->amdService->getAmdParams()
                : [];

            $callSid = $this->conferenceService->dialContactIntoConference(
                $conferenceName,
                $contact,
                $fromNumber,
                $amdParams,
            );

            $call->update(['twilio_call_sid' => $callSid]);
        } catch (\Exception $e) {
            Log::error('DialerOrchestration: failed to dial contact', [
                'call_id' => $call->id,
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);

            $call->markFailed();
            $entry->update(['status' => 'queued']);
            $this->queueService->release($entry);

            return null;
        }

        $session->incrementAttempted();
        $this->agentStateService->setStatus($agent, 'ringing');

        // Schedule queue refresh when this contact's cooldown expires
        // so it reappears in agents' live queues at the exact right moment
        $cooldownMinutes = $campaign->min_minutes_between;
        if ($cooldownMinutes > 0) {
            $companyId = $company->id;
            $contactId = $contact->id;
            dispatch(function () use ($companyId, $contactId) {
                broadcast(new QueueUpdated($companyId, 'cooldown_expired', $contactId));
            })->delay(now()->addMinutes($cooldownMinutes));
        }

        Log::info('DialerOrchestration: call placed', [
            'call_id' => $call->id,
            'session_id' => $session->id,
            'contact_id' => $contact->id,
            'conference_name' => $conferenceName,
            'twilio_call_sid' => $callSid,
        ]);

        return $call;
    }

    // ──────────────────────────────────────
    // Call events
    // ──────────────────────────────────────

    /**
     * Handle a contact answering — transition agent to on_call.
     */
    public function handleAnswered(Call $call): void
    {
        $call->markAnswered();
        $this->agentStateService->setStatus($call->agent, 'on_call');

        if ($call->dialer_session_id) {
            $call->dialerSession->incrementConnected();
        }

        Log::info('DialerOrchestration: call answered', [
            'call_id' => $call->id,
            'agent_id' => $call->agent_id,
        ]);
    }

    /**
     * Handle a call ending (no-answer, busy, completed, failed).
     *
     * Calculates talk time and broadcasts DialerCallEnded.
     * Does NOT remove the contact from the conference — Twilio handles that when
     * the contact hangs up or the call ends.
     */
    public function handleEnded(Call $call): void
    {
        $call->refresh();

        if ($call->answered_at && $call->ended_at) {
            $talkSeconds = $call->answered_at->diffInSeconds($call->ended_at);
            $call->update(['talk_seconds' => $talkSeconds]);

            if ($call->dialer_session_id) {
                $call->dialerSession->addTalkTime($talkSeconds);
            }

            $call->agent->increment('daily_talk_minutes', (int) ceil($talkSeconds / 60));
        }

        $hasMore = $this->queueService->hasCallable($call->campaign);

        broadcast(new DialerCallEnded($call, $call->agent_id, $hasMore));

        Log::info('DialerOrchestration: call ended', [
            'call_id' => $call->id,
            'status' => $call->status,
            'talk_seconds' => $call->talk_seconds,
            'has_more' => $hasMore,
        ]);
    }

    // ──────────────────────────────────────
    // Disposition
    // ──────────────────────────────────────

    /**
     * Save disposition for a completed call.
     *
     * Updates the Call, Contact, CampaignContact, and Campaign stats.
     * Handles exhaustion check, DNC, callback scheduling, and auto-sequence enrollment.
     */
    public function disposition(Call $call, string $disposition, ?string $notes = null): void
    {
        $call->load('contact', 'campaign', 'agent');

        // Update Call record
        $call->markCompleted($disposition, $notes);

        // Update Contact
        $call->contact->markContacted($disposition);

        if (! $call->campaign_id) {
            return;
        }

        $entry = CampaignContact::where('campaign_id', $call->campaign_id)
            ->where('contact_id', $call->contact_id)
            ->first();

        if ($entry) {
            // Release claim
            $this->queueService->release($entry);

            // Always schedule callback follow-up when disposition is callback
            if ($disposition === 'callback') {
                $entry->markCallbackScheduled();
            }

            // Get the routing action for this disposition
            $route = $call->campaign->getDispositionRoute($disposition);

            switch ($route['action']) {
                case 'stay':
                    // Re-queue the contact for more calls (unless it's a callback, already handled)
                    if ($disposition !== 'callback') {
                        $entry->update(['status' => 'queued']);
                    }
                    break;

                case 'move':
                    $entry->exhaust();
                    if (! empty($route['target_campaign_id'])) {
                        CampaignContact::firstOrCreate(
                            ['campaign_id' => $route['target_campaign_id'], 'contact_id' => $entry->contact_id],
                            ['status' => 'queued', 'calls_today' => 0, 'total_attempts' => 0, 'entered_at' => now(), 'sort_order' => 0, 'attempts' => 0],
                        );
                    }
                    break;

                case 'remove':
                    $entry->update(['status' => 'completed']);
                    break;

                case 'dnc':
                    $entry->update(['status' => 'completed']);
                    $call->contact->update(['do_not_call' => true]);
                    break;
            }
        }

        // Campaign stats
        $call->campaign->increment('calls_attempted');

        if (! in_array($disposition, ['no_answer', 'busy', 'failed'])) {
            $call->campaign->increment('calls_connected');
        }

        // Advance to next campaign if this one is exhausted
        $this->maybeAdvanceCampaign($call->campaign, $call->contact);

        // Broadcast disposition + queue update for all agents
        broadcast(new ContactDispositioned(
            companyId: $call->company_id,
            contact: $call->contact,
            disposition: $disposition,
            agentName: $call->agent->user->name ?? 'Unknown',
            campaignName: $call->campaign?->name,
        ));

        broadcast(new QueueUpdated(
            companyId: $call->company_id,
            action: 'dispositioned',
            contactId: $call->contact_id,
        ));

        Log::info('DialerOrchestration: call dispositioned', [
            'call_id' => $call->id,
            'disposition' => $disposition,
            'agent_id' => $call->agent_id,
            'contact_id' => $call->contact_id,
        ]);
    }

    /**
     * Alias for disposition() — called by the dialer blade.
     */
    public function dispositionCall(Call $call, string $disposition, ?string $notes = null): void
    {
        $this->disposition($call, $disposition, $notes);
    }

    // ──────────────────────────────────────
    // Internal helpers
    // ──────────────────────────────────────

    /**
     * If a next_campaign_id is configured and the contact's entry is exhausted,
     * enroll the contact in the next campaign.
     */
    private function maybeAdvanceCampaign(Campaign $campaign, Contact $contact): void
    {
        if (! $campaign->next_campaign_id) {
            return;
        }

        $entry = CampaignContact::where('campaign_id', $campaign->id)
            ->where('contact_id', $contact->id)
            ->first();

        if (! $entry || $entry->status !== 'exhausted') {
            return;
        }

        $nextCampaign = Campaign::find($campaign->next_campaign_id);

        if (! $nextCampaign || ! $nextCampaign->isActive()) {
            return;
        }

        // Avoid duplicate enrollment
        $alreadyEnrolled = CampaignContact::where('campaign_id', $nextCampaign->id)
            ->where('contact_id', $contact->id)
            ->exists();

        if ($alreadyEnrolled) {
            return;
        }

        CampaignContact::create([
            'campaign_id' => $nextCampaign->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
            'entered_at' => now(),
            'added_at' => now(),
        ]);

        $nextCampaign->increment('contact_count');

        Log::info('DialerOrchestration: contact advanced to next campaign', [
            'contact_id' => $contact->id,
            'from_campaign_id' => $campaign->id,
            'to_campaign_id' => $nextCampaign->id,
        ]);
    }

    private function makeConferenceName(): string
    {
        return 'prospector-session-'.Str::ulid();
    }
}

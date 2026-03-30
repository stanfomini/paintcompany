<?php

namespace App\Services\Prospector;

use App\Events\Prospector\ContactDispositioned;
use App\Events\Prospector\DialerCallConnected;
use App\Events\Prospector\DialerCallEnded;
use App\Events\Prospector\DialerCallRinging;
use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\DialerSession;
use Illuminate\Support\Facades\Log;

class PowerDialerService
{
    public function __construct(
        private ConferenceService $conferenceService,
        private NumberSelectionService $numberSelectionService,
        private AgentStateService $agentStateService,
        private TcpaComplianceService $tcpaService,
        private AmdService $amdService,
        private ParallelDialerService $parallelDialerService,
    ) {}

    /**
     * Start a new dialer session for an agent on a campaign.
     *
     * Creates the session, sets agent to available, and returns the session.
     *
     * @param  string  $mode  'single' or 'parallel'
     * @param  int  $parallelLines  Number of simultaneous lines (only for parallel mode)
     */
    public function startSession(Agent $agent, Campaign $campaign, string $mode = 'single', int $parallelLines = 3): DialerSession
    {
        $session = DialerSession::create([
            'agent_id' => $agent->id,
            'campaign_id' => $campaign->id,
            'mode' => $mode,
            'parallel_lines' => $mode === 'parallel' ? $parallelLines : 1,
            'status' => 'active',
            'started_at' => now(),
        ]);

        $this->agentStateService->setStatus($agent, 'available');

        Log::info('Dialer session started', [
            'session_id' => $session->id,
            'session_ulid' => $session->ulid,
            'agent_id' => $agent->id,
            'campaign_id' => $campaign->id,
        ]);

        return $session;
    }

    /**
     * Pause an active dialer session.
     *
     * Sets the session to paused and the agent to break.
     */
    public function pauseSession(DialerSession $session): void
    {
        $session->update(['status' => 'paused']);

        $this->agentStateService->setStatus($session->agent, 'break');

        Log::info('Dialer session paused', [
            'session_id' => $session->id,
            'agent_id' => $session->agent_id,
        ]);
    }

    /**
     * Resume a paused dialer session.
     *
     * Sets the session back to active, agent to available, and dials next.
     */
    public function resumeSession(DialerSession $session): void
    {
        $session->update(['status' => 'active']);

        $this->agentStateService->setStatus($session->agent, 'available');

        Log::info('Dialer session resumed', [
            'session_id' => $session->id,
            'agent_id' => $session->agent_id,
        ]);

        $this->dialNext($session);
    }

    /**
     * End a dialer session.
     *
     * Completes or abandons the session, sets agent offline.
     * Cleans up any active parallel batch locks.
     */
    public function endSession(DialerSession $session): void
    {
        // Clean up parallel batch lock if applicable
        if ($session->isParallel() && $session->current_batch_id) {
            $this->parallelDialerService->clearBatchLock($session);
        }

        if ($session->calls_attempted > 0) {
            $session->complete();
        } else {
            $session->abandon();
        }

        $this->agentStateService->setStatus($session->agent, 'offline');

        Log::info('Dialer session ended', [
            'session_id' => $session->id,
            'agent_id' => $session->agent_id,
            'status' => $session->status,
            'calls_attempted' => $session->calls_attempted,
            'calls_connected' => $session->calls_connected,
        ]);
    }

    /**
     * Dial the next queued contact from the campaign.
     *
     * In parallel mode, delegates to ParallelDialerService to dial multiple
     * contacts simultaneously. Returns the first call from the batch.
     *
     * Gets the next contact, checks TCPA compliance, selects
     * a phone number, and places the call via ConferenceService.
     *
     * Returns null if the queue is empty or all remaining contacts are non-callable.
     */
    public function dialNext(DialerSession $session): ?Call
    {
        if (! $session->isActive()) {
            return null;
        }

        // Delegate to parallel service for parallel mode sessions
        if ($session->isParallel()) {
            $calls = $this->parallelDialerService->dialNextBatch($session);

            return $calls[0] ?? null;
        }

        $session->load('agent.company', 'campaign');

        $agent = $session->agent;
        $campaign = $session->campaign;
        $company = $agent->company;

        $campaignContact = $this->getNextContact($campaign);

        if (! $campaignContact) {
            Log::info('No more contacts in queue', [
                'session_id' => $session->id,
                'campaign_id' => $campaign->id,
            ]);

            return null;
        }

        $contact = $campaignContact->contact;

        // Select outbound number
        $fromNumber = $this->numberSelectionService->selectNumber($company, $contact, $campaign);

        // Get AMD params
        $amdParams = $this->amdService->getAmdParams();

        // Create conference room name
        $conferenceName = $this->conferenceService->createConferenceRoom($session);

        // Mark contact as dialing
        $campaignContact->markDialing();

        // Create the Call record
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
            'started_at' => now(),
        ]);

        // Dial into conference
        try {
            $callSid = $this->conferenceService->dialContactIntoConference(
                $conferenceName,
                $contact,
                $fromNumber,
                $amdParams,
            );

            $call->update([
                'twilio_call_sid' => $callSid,
                'twilio_conference_sid' => $conferenceName,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to dial contact', [
                'call_id' => $call->id,
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);

            $call->markFailed();

            return null;
        }

        // Update session stats
        $session->incrementAttempted();
        $agent->incrementCallCount();

        // Set agent to ringing
        $this->agentStateService->setStatus($agent, 'ringing');

        // Broadcast ringing event
        broadcast(new DialerCallRinging($call, $agent->id));

        Log::info('Call placed', [
            'call_id' => $call->id,
            'call_ulid' => $call->ulid,
            'session_id' => $session->id,
            'contact_id' => $contact->id,
            'twilio_call_sid' => $callSid,
        ]);

        return $call;
    }

    /**
     * Save disposition for a completed call.
     *
     * Updates the call, contact, and campaign stats.
     * Transitions agent to wrap_up and schedules the next dial.
     */
    public function dispositionCall(Call $call, string $disposition, ?string $notes = null): void
    {
        $call->load('contact', 'campaign', 'agent.user', 'agent.company');

        // Update call record
        $call->markCompleted($disposition, $notes);

        // Update contact
        $call->contact->markContacted($disposition);

        // Update campaign contact entry
        if ($call->campaign_id) {
            $campaignContact = CampaignContact::where('campaign_id', $call->campaign_id)
                ->where('contact_id', $call->contact_id)
                ->first();

            if ($campaignContact) {
                $campaignContact->markCompleted($disposition);
            }

            // Update campaign stats
            $call->campaign->increment('calls_attempted');

            if ($disposition !== 'no_answer' && $disposition !== 'busy') {
                $call->campaign->increment('calls_connected');
            }
        }

        // Handle DNC disposition
        if ($disposition === 'dnc') {
            $call->contact->update(['do_not_call' => true]);
        }

        // Broadcast disposition event
        broadcast(new ContactDispositioned(
            companyId: $call->company_id,
            contact: $call->contact,
            disposition: $disposition,
            agentName: $call->agent->user->name ?? 'Unknown',
            campaignName: $call->campaign?->name,
        ));

        // Transition agent to wrap_up (auto-transitions to available after wrap_up_seconds)
        $this->agentStateService->transitionToWrapUp($call->agent);

        Log::info('Call dispositioned', [
            'call_id' => $call->id,
            'disposition' => $disposition,
            'agent_id' => $call->agent_id,
            'contact_id' => $call->contact_id,
        ]);
    }

    /**
     * Handle a call being answered and connected to the agent.
     *
     * Updates call status, sets agent to on_call, and broadcasts.
     */
    public function handleCallConnected(Call $call): void
    {
        $call->markAnswered();

        $this->agentStateService->setStatus($call->agent, 'on_call');

        // Update session stats
        if ($call->dialer_session_id) {
            $call->dialerSession->incrementConnected();
        }

        broadcast(new DialerCallConnected($call, $call->agent_id));

        Log::info('Call connected', [
            'call_id' => $call->id,
            'agent_id' => $call->agent_id,
            'contact_id' => $call->contact_id,
        ]);
    }

    /**
     * Handle a call ending (completed, no answer, busy, failed).
     *
     * Updates duration, broadcasts end event.
     */
    public function handleCallEnded(Call $call): void
    {
        // Calculate talk time
        if ($call->answered_at && $call->ended_at) {
            $talkSeconds = $call->answered_at->diffInSeconds($call->ended_at);

            $call->update(['talk_seconds' => $talkSeconds]);

            // Update session talk time
            if ($call->dialer_session_id) {
                $call->dialerSession->addTalkTime($talkSeconds);
            }

            // Update agent daily talk minutes
            $call->agent->increment('daily_talk_minutes', (int) ceil($talkSeconds / 60));
        }

        // Check if there are more contacts in the queue
        $hasNextContact = false;

        if ($call->campaign_id) {
            $hasNextContact = CampaignContact::where('campaign_id', $call->campaign_id)
                ->where('status', 'queued')
                ->exists();
        }

        broadcast(new DialerCallEnded($call, $call->agent_id, $hasNextContact));

        Log::info('Call ended', [
            'call_id' => $call->id,
            'status' => $call->status,
            'duration_seconds' => $call->duration_seconds,
            'talk_seconds' => $call->talk_seconds,
            'has_next_contact' => $hasNextContact,
        ]);
    }

    /**
     * Get the next queued contact from a campaign, respecting TCPA rules.
     *
     * Skips contacts that fail TCPA compliance checks.
     */
    public function getNextContact(Campaign $campaign): ?CampaignContact
    {
        $campaign->load('company');

        $maxSkips = 50;
        $skipped = 0;

        while ($skipped < $maxSkips) {
            $entry = CampaignContact::where('campaign_id', $campaign->id)
                ->where('status', 'queued')
                ->orderBy('sort_order')
                ->with('contact')
                ->first();

            if (! $entry) {
                return null;
            }

            $contact = $entry->contact;

            // Check if contact exists and is callable
            if (! $contact || $contact->trashed()) {
                $entry->markSkipped();
                $skipped++;

                continue;
            }

            // Check TCPA compliance
            if (! $this->tcpaService->canCall($contact, $campaign->company, $campaign)) {
                $entry->markSkipped();
                $skipped++;

                Log::debug('Skipped contact due to TCPA', [
                    'contact_id' => $contact->id,
                    'campaign_id' => $campaign->id,
                ]);

                continue;
            }

            return $entry;
        }

        Log::warning('Reached max skip limit while searching for next contact', [
            'campaign_id' => $campaign->id,
            'skipped' => $skipped,
        ]);

        return null;
    }
}

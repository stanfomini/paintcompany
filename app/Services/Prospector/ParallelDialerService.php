<?php

namespace App\Services\Prospector;

use App\Events\Prospector\DialerCallConnected;
use App\Events\Prospector\DialerCallRinging;
use App\Models\Prospector\Call;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\DialerSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class ParallelDialerService
{
    /** Default number of parallel lines if not configured on session. */
    private const DEFAULT_PARALLEL_LINES = 3;

    /** Redis lock TTL in seconds — prevents stuck locks if crash occurs. */
    private const LOCK_TTL_SECONDS = 30;

    public function __construct(
        private ConferenceService $conferenceService,
        private NumberSelectionService $numberSelectionService,
        private AgentStateService $agentStateService,
        private TcpaComplianceService $tcpaService,
        private AmdService $amdService,
    ) {}

    /**
     * Dial the next batch of contacts simultaneously.
     *
     * Picks N queued contacts (N = session.parallel_lines), checks TCPA for each,
     * selects outbound numbers, and dials all into the conference at once.
     * First human pickup wins via Redis NX lock.
     *
     * @return array<int, Call> Array of Call models created for this batch
     */
    public function dialNextBatch(DialerSession $session): array
    {
        if (! $session->isActive()) {
            return [];
        }

        $session->load('agent.company', 'campaign');

        $agent = $session->agent;
        $campaign = $session->campaign;
        $company = $agent->company;
        $parallelLines = $session->parallel_lines ?: self::DEFAULT_PARALLEL_LINES;

        // Generate a unique batch ID for this round
        $batchId = Str::ulid()->toBase32();

        // Store the batch ID on the session
        $session->update(['current_batch_id' => $batchId]);

        // Get N contacts for this batch
        $contacts = $this->getNextContacts($session, $parallelLines);

        if ($contacts->isEmpty()) {
            Log::info('No more contacts in queue for parallel batch', [
                'session_id' => $session->id,
                'campaign_id' => $campaign->id,
            ]);

            return [];
        }

        // Rate limit: respect calls_per_second via simple delay
        $this->applyRateLimit($company->id);

        // Create conference room for this batch
        $conferenceName = $this->conferenceService->createConferenceRoom($session);
        $amdParams = $this->amdService->getAmdParams();

        $calls = [];

        foreach ($contacts as $campaignContact) {
            $contact = $campaignContact->contact;

            // Select outbound number
            $fromNumber = $this->numberSelectionService->selectNumber($company, $contact, $campaign);

            // Mark contact as dialing
            $campaignContact->markDialing();

            // Create the Call record
            $call = Call::create([
                'company_id' => $company->id,
                'dialer_session_id' => $session->id,
                'agent_id' => $agent->id,
                'contact_id' => $contact->id,
                'campaign_id' => $campaign->id,
                'batch_id' => $batchId,
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
                Log::error('Failed to dial contact in parallel batch', [
                    'call_id' => $call->id,
                    'contact_id' => $contact->id,
                    'batch_id' => $batchId,
                    'error' => $e->getMessage(),
                ]);

                $call->markFailed();
                $campaignContact->reQueue();

                continue;
            }

            // Update session stats
            $session->incrementAttempted();
            $agent->incrementCallCount();

            $calls[] = $call;

            Log::info('Parallel call placed', [
                'call_id' => $call->id,
                'batch_id' => $batchId,
                'session_id' => $session->id,
                'contact_id' => $contact->id,
                'twilio_call_sid' => $callSid,
                'line' => count($calls),
                'total_lines' => $contacts->count(),
            ]);
        }

        if (! empty($calls)) {
            // Set agent to ringing
            $this->agentStateService->setStatus($agent, 'ringing');

            // Broadcast ringing for the first call (UI shows "dialing N contacts")
            broadcast(new DialerCallRinging($calls[0], $agent->id));
        }

        Log::info('Parallel batch started', [
            'batch_id' => $batchId,
            'session_id' => $session->id,
            'lines_dialed' => count($calls),
            'lines_requested' => $parallelLines,
        ]);

        return $calls;
    }

    /**
     * Handle the first human pickup in a parallel batch.
     *
     * Uses Redis NX lock to ensure exactly one call wins.
     * The winning call connects to the agent. Losers are canceled and re-queued.
     *
     * @return bool True if this call won the lock (first pickup), false if another line already won
     */
    public function handleFirstPickup(Call $call, DialerSession $session): bool
    {
        $lockKey = $this->getBatchLockKey($session);

        // Attempt atomic lock — only the first call succeeds
        $acquired = Redis::set(
            $lockKey,
            $call->id,
            'NX',
            'EX',
            self::LOCK_TTL_SECONDS,
        );

        if (! $acquired) {
            // Another line already won — hang up this call
            Log::info('Parallel line lost to first pickup', [
                'call_id' => $call->id,
                'batch_id' => $call->batch_id,
                'session_id' => $session->id,
            ]);

            $this->cancelCall($call);

            return false;
        }

        // This call won — connect to agent
        Log::info('Parallel line won first pickup', [
            'call_id' => $call->id,
            'batch_id' => $call->batch_id,
            'session_id' => $session->id,
        ]);

        // Mark as answered
        $call->markAnswered();

        // Set agent to on_call
        $this->agentStateService->setStatus($call->agent, 'on_call');

        // Update session stats
        $session->incrementConnected();

        // Cancel the other lines
        $this->cancelOtherLines($session, $call);

        broadcast(new DialerCallConnected($call, $call->agent_id));

        return true;
    }

    /**
     * Cancel all other active calls in this batch except the winning call.
     *
     * Hangs up via Twilio API, marks calls as canceled, and re-queues the contacts.
     */
    public function cancelOtherLines(DialerSession $session, Call $winningCall): void
    {
        $otherCalls = $this->getCurrentBatchCalls($session)
            ->where('id', '!=', $winningCall->id);

        foreach ($otherCalls as $call) {
            $this->cancelCall($call);
        }

        Log::info('Canceled other parallel lines', [
            'session_id' => $session->id,
            'batch_id' => $winningCall->batch_id,
            'winning_call_id' => $winningCall->id,
            'canceled_count' => $otherCalls->count(),
        ]);
    }

    /**
     * Get all calls from the current batch of a session.
     *
     * @return Collection<int, Call>
     */
    public function getCurrentBatchCalls(DialerSession $session): Collection
    {
        if (! $session->current_batch_id) {
            return collect();
        }

        return Call::where('dialer_session_id', $session->id)
            ->where('batch_id', $session->current_batch_id)
            ->get();
    }

    /**
     * Remove the Redis lock after a batch completes.
     */
    public function clearBatchLock(DialerSession $session): void
    {
        $lockKey = $this->getBatchLockKey($session);
        Redis::del($lockKey);

        $session->update(['current_batch_id' => null]);

        Log::debug('Cleared parallel batch lock', [
            'session_id' => $session->id,
        ]);
    }

    /**
     * Get the next N TCPA-compliant queued contacts from a campaign.
     *
     * @return Collection<int, CampaignContact>
     */
    private function getNextContacts(DialerSession $session, int $count): Collection
    {
        $campaign = $session->campaign;
        $campaign->load('company');

        $contacts = collect();
        $maxSkips = $count * 10; // Allow some skips per line
        $skipped = 0;

        while ($contacts->count() < $count && $skipped < $maxSkips) {
            $entry = CampaignContact::where('campaign_id', $campaign->id)
                ->where('status', 'queued')
                ->orderBy('sort_order')
                ->with('contact')
                ->first();

            if (! $entry) {
                break;
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

                Log::debug('Skipped contact due to TCPA in parallel batch', [
                    'contact_id' => $contact->id,
                    'campaign_id' => $campaign->id,
                ]);

                continue;
            }

            // Temporarily mark as dialing to prevent re-selection within this batch
            $entry->markDialing();
            $contacts->push($entry);
        }

        return $contacts;
    }

    /**
     * Cancel a single call — hang up via Twilio, mark canceled, re-queue the contact.
     */
    private function cancelCall(Call $call): void
    {
        // Hang up via Twilio if we have a SID
        if ($call->twilio_call_sid && $call->twilio_conference_sid) {
            try {
                app(ConferenceService::class)->removeParticipant(
                    $call->twilio_conference_sid,
                    $call->twilio_call_sid,
                );
            } catch (\Exception $e) {
                Log::warning('Failed to cancel parallel call via Twilio', [
                    'call_id' => $call->id,
                    'twilio_call_sid' => $call->twilio_call_sid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $call->markCanceled();

        // Re-queue the contact so they can be dialed again
        if ($call->campaign_id && $call->contact_id) {
            $campaignContact = CampaignContact::where('campaign_id', $call->campaign_id)
                ->where('contact_id', $call->contact_id)
                ->first();

            if ($campaignContact) {
                $campaignContact->reQueue();
            }
        }
    }

    /**
     * Apply rate limiting — simple delay to respect calls_per_second config.
     */
    private function applyRateLimit(int $companyId): void
    {
        $callsPerSecond = app(SettingsService::class)->get('rate_limits.calls_per_second', 1);

        if ($callsPerSecond <= 0) {
            return;
        }

        $rateLimitKey = "prospector:rate_limit:calls:{$companyId}";
        $intervalMs = (int) (1000 / $callsPerSecond);

        // Use Redis to track last call timestamp
        $lastCallTime = Redis::get($rateLimitKey);

        if ($lastCallTime) {
            $elapsed = (int) ((microtime(true) * 1000) - (float) $lastCallTime);

            if ($elapsed < $intervalMs) {
                usleep(($intervalMs - $elapsed) * 1000);
            }
        }

        Redis::setex($rateLimitKey, 5, (string) (microtime(true) * 1000));
    }

    /**
     * Get the Redis key for the parallel batch lock.
     */
    private function getBatchLockKey(DialerSession $session): string
    {
        return "prospector:parallel:{$session->ulid}:connected";
    }
}

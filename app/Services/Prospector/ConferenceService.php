<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Agent;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\DialerSession;
use App\Models\Prospector\PhoneNumber;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Twilio\Jwt\AccessToken;
use Twilio\Jwt\Grants\VoiceGrant;
use Twilio\Rest\Client;

class ConferenceService
{
    /**
     * Generate a Twilio Access Token for the agent's browser WebRTC connection.
     *
     * The token includes a VoiceGrant scoped to the TwiML App,
     * allowing the agent to make and receive calls via the JS SDK.
     */
    public function generateAccessToken(Agent $agent, ?Company $company = null): string
    {
        if ($company) {
            $company->load('twilioAccount');
            $twilioAccount = $company->twilioAccount;
        } else {
            $agent->load('company.twilioAccount');
            $twilioAccount = $agent->company->twilioAccount;
            $company = $agent->company;
        }

        if (! $twilioAccount) {
            throw new RuntimeException(
                "No Twilio account configured for company {$agent->company->name}."
            );
        }

        // Read API Key from database (per-account), fall back to config
        $apiKeySid = $twilioAccount->api_key_sid ?: config('prospector.twilio.api_key_sid');
        $apiKeySecret = $twilioAccount->api_key_secret ?: config('prospector.twilio.api_key_secret');
        $twimlAppSid = $twilioAccount->twiml_app_sid ?: config('prospector.twilio.twiml_app_sid');

        if (! $apiKeySid || ! $apiKeySecret) {
            throw new RuntimeException(
                "Twilio API Key not configured for account {$twilioAccount->name}. Edit the account in Prospector Settings and add your API Key SID and Secret."
            );
        }

        $token = new AccessToken(
            $twilioAccount->account_sid,
            $apiKeySid,
            $apiKeySecret,
            3600,
            "agent-{$agent->id}",
        );

        $voiceGrant = new VoiceGrant;

        if ($twimlAppSid) {
            $voiceGrant->setOutgoingApplicationSid($twimlAppSid);
        }

        $voiceGrant->setIncomingAllow(true);

        $token->addGrant($voiceGrant);

        Log::debug('Generated Twilio access token for agent', [
            'agent_id' => $agent->id,
            'user_id' => $agent->user_id,
            'company_id' => $agent->company_id,
        ]);

        return $token->toJWT();
    }

    /**
     * Generate a unique conference room name for a dialer session.
     */
    public function createConferenceRoom(DialerSession $session): string
    {
        return "prospector-session-{$session->ulid}";
    }

    /**
     * Dial a contact into an existing conference room.
     *
     * Uses the Twilio Participants API to create an outbound call
     * to the contact and connect them to the conference.
     *
     * @param  array<string, mixed>  $amdParams
     */
    public function dialContactIntoConference(
        string $conferenceName,
        Contact $contact,
        PhoneNumber $fromNumber,
        array $amdParams = [],
    ): string {
        $fromNumber->load('twilioAccount');

        $client = $this->getTwilioClient($fromNumber);

        $participantParams = [
            'from' => $fromNumber->getEffectiveCallerId(),
            'to' => $contact->phone,
            'statusCallback' => route('prospector.webhook.twilio.voice.status'),
            'statusCallbackMethod' => 'POST',
            'statusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
            'timeout' => config('prospector.defaults.ring_timeout_seconds', 30),
        ];

        $participantParams = array_merge($participantParams, $amdParams);

        $participant = $client->conferences($conferenceName)
            ->participants
            ->create(
                $fromNumber->getEffectiveCallerId(),
                $contact->phone,
                $participantParams,
            );

        $callSid = $participant->callSid;

        Log::info('Dialed contact into conference', [
            'conference' => $conferenceName,
            'contact_id' => $contact->id,
            'from' => $fromNumber->phone_number,
            'to' => $contact->phone,
            'call_sid' => $callSid,
        ]);

        $fromNumber->markUsed();

        return $callSid;
    }

    /**
     * Warm transfer: add a third party to the conference while agent stays.
     * Agent can brief the recipient, then drop themselves.
     */
    public function warmTransfer(string $conferenceName, string $toNumber, PhoneNumber $fromNumber): string
    {
        $client = $this->getTwilioClient($fromNumber);

        $participant = $client->conferences($conferenceName)
            ->participants
            ->create(
                $fromNumber->getEffectiveCallerId(),
                $toNumber,
                [
                    'statusCallback' => route('prospector.webhook.twilio.voice.status'),
                    'statusCallbackMethod' => 'POST',
                    'statusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
                    'timeout' => 30,
                ]
            );

        Log::info('Warm transfer initiated', [
            'conference' => $conferenceName,
            'to' => $toNumber,
            'from' => $fromNumber->phone_number,
            'call_sid' => $participant->callSid,
        ]);

        return $participant->callSid;
    }

    /**
     * Cold transfer: add third party to conference, then remove the agent.
     */
    public function coldTransfer(string $conferenceName, string $agentCallSid, string $toNumber, PhoneNumber $fromNumber): string
    {
        $recipientSid = $this->warmTransfer($conferenceName, $toNumber, $fromNumber);

        // Remove agent from conference so only contact + recipient remain
        $this->removeParticipant($conferenceName, $agentCallSid);

        Log::info('Cold transfer completed — agent removed', [
            'conference' => $conferenceName,
            'agent_call_sid' => $agentCallSid,
            'recipient_sid' => $recipientSid,
        ]);

        return $recipientSid;
    }

    /**
     * Transfer to another agent by dialing their WebRTC client identity into the conference.
     */
    public function transferToAgent(string $conferenceName, int $targetAgentId): string
    {
        $client = $this->getDefaultClient();

        $identity = "agent-{$targetAgentId}";

        $participant = $client->conferences($conferenceName)
            ->participants
            ->create(
                "client:{$identity}",
                "client:{$identity}",
                [
                    'statusCallback' => route('prospector.webhook.twilio.voice.status'),
                    'statusCallbackMethod' => 'POST',
                    'statusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
                    'timeout' => 30,
                ]
            );

        Log::info('Agent-to-agent transfer initiated', [
            'conference' => $conferenceName,
            'target_agent_id' => $targetAgentId,
            'target_identity' => $identity,
            'call_sid' => $participant->callSid,
        ]);

        return $participant->callSid;
    }

    /**
     * Place or unhold a participant in a conference.
     */
    public function holdParticipant(string $conferenceSid, string $callSid, bool $hold): void
    {
        $client = $this->getDefaultClient();

        try {
            $params = ['hold' => $hold];

            $client->conferences($conferenceSid)
                ->participants($callSid)
                ->update($params);

            Log::info($hold ? 'Participant placed on hold' : 'Participant taken off hold', [
                'conference_sid' => $conferenceSid,
                'call_sid' => $callSid,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to update participant hold status', [
                'conference_sid' => $conferenceSid,
                'call_sid' => $callSid,
                'hold' => $hold,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove a participant from a conference by their call SID.
     */
    public function removeParticipant(string $conferenceSid, string $callSid): void
    {
        $client = $this->getDefaultClient();

        try {
            $client->conferences($conferenceSid)
                ->participants($callSid)
                ->update(['status' => 'completed']);

            Log::info('Removed participant from conference', [
                'conference_sid' => $conferenceSid,
                'call_sid' => $callSid,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to remove participant from conference', [
                'conference_sid' => $conferenceSid,
                'call_sid' => $callSid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Supervisor listen: join conference as muted participant.
     * Supervisor hears everything but no one hears them.
     */
    public function supervisorListen(string $conferenceName, Agent $supervisor): string
    {
        $client = $this->getDefaultClient();

        $identity = "agent-{$supervisor->id}";

        $participant = $client->conferences($conferenceName)
            ->participants
            ->create(
                "client:{$identity}",
                "client:{$identity}",
                ['muted' => true, 'beep' => false, 'coaching' => false]
            );

        Log::info('Supervisor listening to conference', [
            'conference' => $conferenceName,
            'supervisor_id' => $supervisor->id,
            'call_sid' => $participant->callSid,
        ]);

        return $participant->callSid;
    }

    /**
     * Supervisor whisper: join conference in coaching mode.
     * Supervisor can speak to the coached participant only (not heard by others).
     */
    public function supervisorWhisper(string $conferenceName, Agent $supervisor, string $agentCallSid): string
    {
        $client = $this->getDefaultClient();

        $identity = "agent-{$supervisor->id}";

        $participant = $client->conferences($conferenceName)
            ->participants
            ->create(
                "client:{$identity}",
                "client:{$identity}",
                ['coaching' => true, 'callSidToCoach' => $agentCallSid, 'beep' => false]
            );

        Log::info('Supervisor whispering in conference', [
            'conference' => $conferenceName,
            'supervisor_id' => $supervisor->id,
            'coached_call_sid' => $agentCallSid,
            'call_sid' => $participant->callSid,
        ]);

        return $participant->callSid;
    }

    /**
     * Supervisor barge: join conference as full participant.
     * Everyone hears everyone.
     */
    public function supervisorBarge(string $conferenceName, Agent $supervisor): string
    {
        $client = $this->getDefaultClient();

        $identity = "agent-{$supervisor->id}";

        $participant = $client->conferences($conferenceName)
            ->participants
            ->create(
                "client:{$identity}",
                "client:{$identity}",
                ['muted' => false, 'beep' => false]
            );

        Log::info('Supervisor barging into conference', [
            'conference' => $conferenceName,
            'supervisor_id' => $supervisor->id,
            'call_sid' => $participant->callSid,
        ]);

        return $participant->callSid;
    }

    /**
     * End an entire conference by updating its status to completed.
     */
    public function endConference(string $conferenceSid): void
    {
        $client = $this->getDefaultClient();

        try {
            $client->conferences($conferenceSid)
                ->update(['status' => 'completed']);

            Log::info('Conference ended', ['conference_sid' => $conferenceSid]);
        } catch (\Exception $e) {
            Log::warning('Failed to end conference', [
                'conference_sid' => $conferenceSid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * List current participants in a conference.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getConferenceParticipants(string $conferenceSid): array
    {
        $client = $this->getDefaultClient();

        try {
            $participants = $client->conferences($conferenceSid)
                ->participants
                ->read();

            return collect($participants)->map(fn ($p) => [
                'call_sid' => $p->callSid,
                'muted' => $p->muted,
                'hold' => $p->hold,
                'status' => $p->status,
            ])->all();
        } catch (\Exception $e) {
            Log::warning('Failed to list conference participants', [
                'conference_sid' => $conferenceSid,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Get a Twilio client from a phone number's associated account.
     */
    private function getTwilioClient(PhoneNumber $phoneNumber): Client
    {
        $account = $phoneNumber->twilioAccount;

        if (! $account) {
            throw new RuntimeException(
                "Phone number {$phoneNumber->phone_number} has no associated Twilio account."
            );
        }

        return $account->getClient();
    }

    /**
     * Get a Twilio client using the primary account.
     */
    private function getDefaultClient(): Client
    {
        $account = \App\Models\Prospector\TwilioAccount::where('is_primary', true)
            ->where('is_active', true)
            ->first()
            ?? \App\Models\Prospector\TwilioAccount::where('is_active', true)->first();

        if (! $account) {
            throw new RuntimeException('No active Twilio account configured.');
        }

        return $account->getClient();
    }
}

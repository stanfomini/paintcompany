<?php

namespace App\Http\Controllers\Prospector;

use App\Events\Prospector\InboundCallRinging;
use App\Http\Controllers\Controller;
use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\PhoneNumber;
use App\Services\Prospector\ConferenceService;
use App\Services\Prospector\WebhookIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Twilio\TwiML\VoiceResponse;

class TwilioVoiceController extends Controller
{
    public function __construct(
        private ConferenceService $conferenceService,
    ) {}

    /**
     * GET /prospector/voice/token
     *
     * Generate a Twilio Access Token for the authenticated agent's
     * browser WebRTC connection via the Twilio Voice JS SDK.
     */
    public function token(Request $request): JsonResponse
    {
        $user = Auth::user();
        $requestedCompanyId = $request->query('company_id');

        // Credentials can live in the DB (per-account) or fall back to config
        $hasConfigCredentials = config('prospector.twilio.api_key_sid') && config('prospector.twilio.api_key_secret');

        if ($requestedCompanyId) {
            // Specific company requested — must have an active Twilio account
            $company = Company::where('id', $requestedCompanyId)
                ->where('is_active', true)
                ->whereHas('twilioAccount', function ($q) use ($hasConfigCredentials) {
                    $q->where('is_active', true);
                    if (! $hasConfigCredentials) {
                        $q->whereNotNull('api_key_sid')->whereNotNull('api_key_secret');
                    }
                })
                ->first();

            if (! $company) {
                return response()->json([
                    'error' => 'Requested company does not have Voice SDK credentials configured.',
                ], 422);
            }
        } else {
            // Find company with DB credentials first, then any with active account (config fallback)
            $company = Company::where('is_active', true)
                ->whereHas('twilioAccount', function ($q) {
                    $q->whereNotNull('api_key_sid')
                        ->whereNotNull('api_key_secret')
                        ->where('is_active', true);
                })
                ->first();

            if (! $company && $hasConfigCredentials) {
                $company = Company::where('is_active', true)
                    ->whereHas('twilioAccount', fn ($q) => $q->where('is_active', true))
                    ->first();
            }
        }

        if (! $company) {
            // Fall back to any active company so the agent record still gets created
            $company = Company::where('is_active', true)->first();

            if (! $company) {
                return response()->json(['error' => 'No active company configured.'], 422);
            }

            // Create agent but explain why phone can't connect
            Agent::firstOrCreate(
                ['user_id' => $user->id, 'company_id' => $company->id],
                ['status' => 'offline', 'max_concurrent_lines' => 1, 'wrap_up_seconds' => 30],
            );

            return response()->json([
                'error' => 'No company has Voice SDK credentials configured. Go to Prospector Settings > Accounts and add API Key SID, API Key Secret, and TwiML App SID.',
            ], 422);
        }

        $agent = Agent::firstOrCreate(
            ['user_id' => $user->id, 'company_id' => $company->id],
            ['status' => 'offline', 'max_concurrent_lines' => 1, 'wrap_up_seconds' => 30],
        );

        try {
            $token = $this->conferenceService->generateAccessToken($agent, $company);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'token' => $token,
            'company_id' => $company->id,
        ]);
    }

    /**
     * POST /prospector/webhook/twilio/voice/incoming
     *
     * TwiML endpoint called by Twilio when the Voice SDK initiates a call.
     * Returns simple TwiML — all orchestration is done by the Livewire component.
     *
     * For conference joins (outbound calls, transfers): reads conference param, returns <Conference> TwiML.
     * For client-to-client: dials the target client.
     */
    public function handleIncoming(Request $request): Response
    {
        $to = $request->input('To', '');
        $conferenceName = $request->input('conference', '');

        // Conference join (outbound calls, transfers, supervisor, parallel dial)
        if ($conferenceName) {
            $response = new VoiceResponse;
            $dial = $response->dial();
            $dial->conference($conferenceName, [
                'startConferenceOnEnter' => true,
                'endConferenceOnExit' => true,
                'beep' => false,
            ]);

            return $this->twimlResponse($response);
        }

        // Client-to-client (should not happen in normal flow)
        if (str_starts_with($to, 'client:')) {
            $response = new VoiceResponse;
            $dial = $response->dial();
            $dial->client(substr($to, 7));

            return $this->twimlResponse($response);
        }

        // Fallback
        $response = new VoiceResponse;
        $response->say('No call parameters specified.');
        $response->hangup();

        return $this->twimlResponse($response);
    }

    /**
     * POST /prospector/webhook/twilio/voice/inbound
     *
     * Handle an inbound call to a Prospector phone number.
     * Routes to an available agent or falls back to voicemail/IVR.
     */
    public function handleInboundCall(Request $request): Response
    {
        $toNumber = $request->input('To', '');
        $fromNumber = $request->input('From', '');
        $callSid = $request->input('CallSid', '');

        // Find the Prospector phone number
        $phoneNumber = PhoneNumber::where('phone_number', $toNumber)->first();

        if (! $phoneNumber || ! $phoneNumber->company_id) {
            Log::warning('Inbound call to unknown Prospector number', [
                'to' => $toNumber,
                'from' => $fromNumber,
            ]);

            return $this->voicemailTwiml();
        }

        $company = $phoneNumber->company;

        // Find or create contact with pipeline stage + broadcasting
        $intake = app(\App\Services\Prospector\ContactIntakeService::class);
        $result = $intake->findOrCreate($company, $fromNumber, [
            'source' => 'inbound_call',
        ]);
        $contact = $result->contact;

        // Flag this contact as priority in any active campaign queues
        $queueEntries = CampaignContact::where('contact_id', $contact->id)
            ->where('status', 'queued')
            ->get();

        if ($queueEntries->isNotEmpty()) {
            foreach ($queueEntries as $entry) {
                $entry->markPriority('inbound_call');
            }
        } elseif ($company->default_campaign_id) {
            // Auto-enroll in default campaign at front with priority
            $defaultCampaign = Campaign::find($company->default_campaign_id);
            if ($defaultCampaign) {
                app(WebhookIntakeService::class)->enrollAtFront($contact, $defaultCampaign);
                CampaignContact::where('contact_id', $contact->id)
                    ->where('campaign_id', $company->default_campaign_id)
                    ->update(['is_priority' => true, 'priority_reason' => 'inbound_call']);
            }
        }

        // Check IVR config with business hours awareness
        $ivrFlow = $company->getActiveIvrFlow();

        if ($ivrFlow) {
            return $this->ivrGreetingTwiml($ivrFlow, $company->id, $contact->id, $callSid);
        }

        // No IVR — check business hours for direct routing
        if ($company->isBusinessHours()) {
            return $this->routeToAgent($company, $contact, $fromNumber, $toNumber, $callSid);
        }

        // After hours, no IVR — voicemail
        return $this->afterHoursVoicemailTwiml($company);
    }

    /**
     * POST /prospector/webhook/twilio/voice/ivr-response
     *
     * Handle IVR digit input from caller.
     */
    public function handleIvrResponse(Request $request): Response
    {
        $digits = $request->input('Digits', '');
        $companyId = $request->input('company_id');
        $contactId = $request->input('contact_id');
        $callSid = $request->input('CallSid', '');

        $company = Company::find($companyId);
        if (! $company) {
            return $this->voicemailTwiml();
        }

        $contact = Contact::find($contactId);
        if (! $contact) {
            return $this->voicemailTwiml();
        }

        // Get the active flow (business or after hours)
        $flow = $company->getActiveIvrFlow();
        if (! $flow) {
            return $this->voicemailTwiml();
        }

        $options = $flow['options'] ?? [];
        $selectedOption = $options[$digits] ?? null;

        if (! $selectedOption) {
            return $this->ivrGreetingTwiml($flow, $company->id, $contact->id, $callSid);
        }

        $action = $selectedOption['action'] ?? 'voicemail';

        return match ($action) {
            'route_agent' => $this->routeToAgent(
                $company,
                $contact,
                $request->input('From', $contact->phone),
                $request->input('To', ''),
                $callSid,
            ),
            'play_info' => $this->playInfoTwiml(
                $selectedOption['message'] ?? 'No information available.',
                $flow,
                $company->id,
                $contact->id,
                $callSid,
            ),
            'forward' => $this->forwardTwiml($selectedOption['forward_to'] ?? ''),
            default => $this->voicemailTwiml(),
        };
    }

    /**
     * Route an inbound call to an available agent via conference.
     */
    protected function routeToAgent(Company $company, Contact $contact, string $fromNumber, string $toNumber, string $callSid): Response
    {
        // Find ALL available agents via the pivot table
        $agents = Agent::whereHas('companies', fn ($q) => $q->where('prospector_companies.id', $company->id))
            ->whereIn('status', ['available', 'nearby'])
            ->get();

        if ($agents->isEmpty()) {
            Log::info('No available agents for inbound call', [
                'company_id' => $company->id,
                'from' => $fromNumber,
            ]);

            return $this->noAgentTwiml($company);
        }

        // Check if the agent has an active dialer session — route into their persistent conference
        $activeSession = \App\Models\Prospector\DialerSession::where('agent_id', $agents->first()->id)
            ->where('status', 'active')
            ->first();

        $conferenceName = ($activeSession && $activeSession->conference_name)
            ? $activeSession->conference_name
            : 'inbound-'.Str::ulid();

        $call = Call::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'agent_id' => $agents->first()->id,
            'direction' => 'inbound',
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
            'twilio_call_sid' => $callSid,
            'status' => 'ringing',
            'started_at' => now(),
        ]);

        $agentIds = $agents->pluck('id')->all();

        broadcast(new InboundCallRinging(
            call: $call,
            company: $company,
            conferenceName: $conferenceName,
            agentIds: $agentIds,
        ));

        Log::info('Inbound call routed to agents', [
            'call_id' => $call->id,
            'company_id' => $company->id,
            'agent_ids' => $agentIds,
            'contact_id' => $contact->id,
            'conference' => $conferenceName,
        ]);

        return $this->conferenceJoinTwiml($conferenceName);
    }

    /**
     * Generate TwiML to put the caller into a conference room (with hold music).
     */
    protected function conferenceJoinTwiml(string $conferenceName): Response
    {
        $response = new VoiceResponse;
        $response->say('Please hold while we connect you to an agent.');
        $dial = $response->dial();
        $dial->conference($conferenceName, [
            'startConferenceOnEnter' => true,
            'endConferenceOnExit' => true,
            'beep' => false,
            'waitUrl' => 'http://twimlets.com/holdmusic?Bucket=com.twilio.music.classical',
        ]);

        return $this->twimlResponse($response);
    }

    /**
     * Generate TwiML for voicemail fallback.
     */
    protected function voicemailTwiml(): Response
    {
        $response = new VoiceResponse;
        $response->say('Sorry, no one is available to take your call. Please leave a message after the beep.');
        $response->record([
            'maxLength' => 120,
            'action' => route('prospector.webhook.twilio.voice.status'),
        ]);
        $response->say('We did not receive a recording. Goodbye.');
        $response->hangup();

        return $this->twimlResponse($response);
    }

    /**
     * Generate TwiML when no agents are available.
     */
    protected function noAgentTwiml(Company $company): Response
    {
        $response = new VoiceResponse;
        $response->say("Thank you for calling {$company->name}. All of our agents are currently busy. Please leave a message after the beep.");
        $response->record([
            'maxLength' => 120,
            'action' => route('prospector.webhook.twilio.voice.status'),
        ]);
        $response->say('We did not receive a recording. Goodbye.');
        $response->hangup();

        return $this->twimlResponse($response);
    }

    /**
     * Generate TwiML for IVR greeting with digit gathering.
     */
    protected function ivrGreetingTwiml(array $ivr, int $companyId, int $contactId, string $callSid): Response
    {
        $response = new VoiceResponse;
        $greeting = $ivr['greeting'] ?? 'Thank you for calling. Please hold.';

        $ivrResponseUrl = route('prospector.webhook.twilio.voice.ivr-response', [
            'company_id' => $companyId,
            'contact_id' => $contactId,
        ]);

        $gather = $response->gather([
            'input' => 'dtmf',
            'numDigits' => 1,
            'action' => $ivrResponseUrl,
            'method' => 'POST',
        ]);
        $gather->say($greeting);

        // If no input, repeat once then go to voicemail
        $response->say('We did not receive any input. Goodbye.');
        $response->hangup();

        return $this->twimlResponse($response);
    }

    /**
     * Generate TwiML that plays an info message then re-gathers IVR input.
     */
    protected function playInfoTwiml(string $message, array $flow, int $companyId, int $contactId, string $callSid): Response
    {
        $response = new VoiceResponse;
        $response->say($message);

        $ivrResponseUrl = route('prospector.webhook.twilio.voice.ivr-response', [
            'company_id' => $companyId,
            'contact_id' => $contactId,
        ]);

        $greeting = $flow['greeting'] ?? 'Please make a selection.';
        $gather = $response->gather([
            'input' => 'dtmf',
            'numDigits' => 1,
            'action' => $ivrResponseUrl,
            'method' => 'POST',
        ]);
        $gather->say($greeting);

        $response->say('We did not receive any input. Goodbye.');
        $response->hangup();

        return $this->twimlResponse($response);
    }

    /**
     * Generate TwiML to forward the call to an external number.
     */
    protected function forwardTwiml(string $forwardTo): Response
    {
        $response = new VoiceResponse;

        if (empty($forwardTo)) {
            $response->say('Sorry, we could not connect your call. Goodbye.');
            $response->hangup();

            return $this->twimlResponse($response);
        }

        $response->say('Please hold while we transfer your call.');
        $dial = $response->dial();
        $dial->number($forwardTo);

        return $this->twimlResponse($response);
    }

    /**
     * Generate TwiML for after-hours voicemail with company name.
     */
    protected function afterHoursVoicemailTwiml(Company $company): Response
    {
        $response = new VoiceResponse;
        $response->say("Thank you for calling {$company->name}. We are currently closed. Please leave a message after the beep and we will return your call.");
        $response->record([
            'maxLength' => 120,
            'action' => route('prospector.webhook.twilio.voice.status'),
        ]);
        $response->say('We did not receive a recording. Goodbye.');
        $response->hangup();

        return $this->twimlResponse($response);
    }

    /**
     * Return a TwiML XML response.
     */
    protected function twimlResponse(VoiceResponse $response): Response
    {
        return response($response->asXML(), 200)
            ->header('Content-Type', 'application/xml');
    }
}

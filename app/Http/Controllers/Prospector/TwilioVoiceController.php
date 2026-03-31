<?php

namespace App\Http\Controllers\Prospector;

use App\Events\Prospector\DialerCallRinging;
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
    public function token(): JsonResponse
    {
        $user = Auth::user();

        // Find a company with fully configured Twilio credentials (API key set)
        $company = Company::where('is_active', true)
            ->whereHas('twilioAccount', function ($q) {
                $q->whereNotNull('api_key_sid')
                    ->whereNotNull('api_key_secret')
                    ->where('is_active', true);
            })
            ->first();

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
            $token = $this->conferenceService->generateAccessToken($agent);
        } catch (\RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['token' => $token]);
    }

    /**
     * POST /prospector/webhook/twilio/voice/incoming
     *
     * TwiML endpoint called by Twilio when the Voice SDK initiates
     * or receives a call. Returns TwiML that connects the caller
     * to a conference room.
     */
    public function handleIncoming(Request $request): Response
    {
        $to = $request->input('To', '');
        $conferenceName = $request->input('conference', '');

        $response = new VoiceResponse;

        if ($conferenceName) {
            $dial = $response->dial();
            $dial->conference($conferenceName, [
                'startConferenceOnEnter' => true,
                'endConferenceOnExit' => false,
                'beep' => false,
            ]);
        } elseif (str_starts_with($to, 'client:')) {
            $dial = $response->dial();
            $dial->client(substr($to, 7));
        } else {
            $response->say('No conference specified.');
            $response->hangup();
        }

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

        // Check if company has IVR enabled
        $settings = $company->settings ?? [];
        $ivr = $settings['ivr'] ?? null;

        if ($ivr && ($ivr['enabled'] ?? false)) {
            return $this->ivrGreetingTwiml($ivr, $company->id, $contact->id, $callSid);
        }

        // Direct routing — find available agent
        return $this->routeToAgent($company, $contact, $fromNumber, $toNumber, $callSid);
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

        $settings = $company->settings ?? [];
        $ivr = $settings['ivr'] ?? [];
        $options = $ivr['options'] ?? [];

        $selectedOption = $options[$digits] ?? null;

        if (! $selectedOption) {
            // Invalid digit — replay IVR
            return $this->ivrGreetingTwiml($ivr, $company->id, $contact->id, $callSid);
        }

        $action = $selectedOption['action'] ?? 'voicemail';

        if ($action === 'route_agent') {
            $fromNumber = $request->input('From', $contact->phone);
            $toNumber = $request->input('To', '');

            return $this->routeToAgent($company, $contact, $fromNumber, $toNumber, $callSid);
        }

        // Default: voicemail
        return $this->voicemailTwiml();
    }

    /**
     * Route an inbound call to an available agent via conference.
     */
    protected function routeToAgent(Company $company, Contact $contact, string $fromNumber, string $toNumber, string $callSid): Response
    {
        $agent = Agent::where('company_id', $company->id)
            ->where('status', 'available')
            ->first();

        if (! $agent) {
            Log::info('No available agents for inbound call', [
                'company_id' => $company->id,
                'from' => $fromNumber,
            ]);

            return $this->noAgentTwiml($company);
        }

        $conferenceName = 'inbound-'.Str::ulid();

        $call = Call::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'agent_id' => $agent->id,
            'direction' => 'inbound',
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
            'twilio_call_sid' => $callSid,
            'status' => 'ringing',
            'started_at' => now(),
        ]);

        broadcast(new DialerCallRinging($call, $agent->id));

        Log::info('Inbound call routed to agent', [
            'call_id' => $call->id,
            'company_id' => $company->id,
            'agent_id' => $agent->id,
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
     * Return a TwiML XML response.
     */
    protected function twimlResponse(VoiceResponse $response): Response
    {
        return response($response->asXML(), 200)
            ->header('Content-Type', 'application/xml');
    }
}

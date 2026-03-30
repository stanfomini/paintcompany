<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\RvmDrop;
use App\Models\Prospector\RvmTemplate;
use App\Models\Prospector\SequenceEnrollment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RvmService
{
    /**
     * Drop a ringless voicemail to a contact.
     *
     * Creates the RvmDrop record and dispatches to the appropriate provider.
     */
    public function drop(
        Company $company,
        Contact $contact,
        RvmTemplate $template,
        ?string $method = null,
        ?SequenceEnrollment $enrollment = null,
    ): RvmDrop {
        $method ??= $this->getDefaultMethod($company);

        $fromNumber = $company->getMainNumber();

        if (! $fromNumber) {
            throw new RuntimeException(
                "No main phone number configured for company {$company->name}."
            );
        }

        $drop = RvmDrop::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'template_id' => $template->id,
            'sequence_enrollment_id' => $enrollment?->id,
            'method' => $method,
            'status' => 'queued',
            'from_number' => $fromNumber->phone_number,
            'to_number' => $contact->phone,
        ]);

        try {
            match ($method) {
                'twilio_amd' => $this->dropViaTwilioAmd($drop),
                'slybroadcast' => $this->dropViaSlybroadcast($drop),
                default => throw new RuntimeException("Unknown RVM method: {$method}"),
            };
        } catch (\Exception $e) {
            $drop->markFailed($e->getMessage());

            Log::error('RVM drop failed', [
                'drop_id' => $drop->id,
                'method' => $method,
                'contact_id' => $contact->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $drop;
    }

    /**
     * Drop voicemail via Twilio AMD (Answering Machine Detection).
     *
     * Calls the contact with AMD enabled. When machine is detected (DetectMessageEnd),
     * plays the template audio. When human answers, hangs up immediately.
     */
    public function dropViaTwilioAmd(RvmDrop $drop): void
    {
        $drop->loadMissing(['template', 'company.mainPhoneNumber.twilioAccount']);

        $fromNumber = $drop->company->mainPhoneNumber;

        if (! $fromNumber?->twilioAccount) {
            throw new RuntimeException('No Twilio account configured for this phone number.');
        }

        $client = $fromNumber->twilioAccount->getClient();

        $audioUrl = $this->getAudioUrl($drop->template);

        // TwiML that plays the voicemail audio
        $twimlUrl = route('prospector.webhook.twilio.rvm.twiml', ['drop' => $drop->id]);

        $call = $client->calls->create(
            $drop->to_number,
            $drop->from_number,
            [
                'url' => $twimlUrl,
                'machineDetection' => 'DetectMessageEnd',
                'asyncAmd' => true,
                'asyncAmdStatusCallback' => route('prospector.webhook.twilio.rvm.amd', ['drop' => $drop->id]),
                'statusCallback' => route('prospector.webhook.twilio.rvm.status', ['drop' => $drop->id]),
                'timeout' => 25,
            ]
        );

        $drop->markSent($call->sid);

        Log::info('RVM Twilio AMD call initiated', [
            'drop_id' => $drop->id,
            'call_sid' => $call->sid,
            'to' => $drop->to_number,
        ]);
    }

    /**
     * Drop voicemail via Slybroadcast API.
     */
    public function dropViaSlybroadcast(RvmDrop $drop): void
    {
        $drop->loadMissing('template');

        $audioUrl = $this->getAudioUrl($drop->template);

        $response = Http::asForm()->post('https://www.slybroadcast.com/gateway/vmb.asp', [
            'c_uid' => config('services.slybroadcast.username'),
            'c_password' => config('services.slybroadcast.password'),
            'c_phone' => ltrim($drop->to_number, '+'),
            'c_callerid' => ltrim($drop->from_number, '+'),
            'c_audio' => $audioUrl,
            'c_url' => route('prospector.webhook.twilio.rvm.callback'),
            'c_date' => 'now',
        ]);

        if ($response->successful()) {
            $body = $response->body();

            // Slybroadcast returns "OK" with a session ID
            if (str_starts_with($body, 'OK')) {
                $providerId = trim(str_replace('OK', '', $body));
                $drop->markSent($providerId ?: 'slybroadcast-'.now()->timestamp);

                Log::info('RVM Slybroadcast submitted', [
                    'drop_id' => $drop->id,
                    'provider_id' => $providerId,
                ]);
            } else {
                $drop->markFailed("Slybroadcast error: {$body}");

                Log::error('RVM Slybroadcast error', [
                    'drop_id' => $drop->id,
                    'response' => $body,
                ]);
            }
        } else {
            $drop->markFailed("Slybroadcast HTTP error: {$response->status()}");

            Log::error('RVM Slybroadcast HTTP error', [
                'drop_id' => $drop->id,
                'status' => $response->status(),
            ]);
        }
    }

    /**
     * Handle a provider callback to update drop status.
     */
    public function handleCallback(string $providerId, string $status): void
    {
        $drop = RvmDrop::where('provider_id', $providerId)->first();

        if (! $drop) {
            Log::warning('RVM callback: drop not found', ['provider_id' => $providerId]);

            return;
        }

        match ($status) {
            'delivered', 'completed' => $drop->markDelivered(),
            'failed', 'busy', 'no-answer' => $drop->markFailed("Provider status: {$status}"),
            default => Log::info('RVM callback: unhandled status', [
                'drop_id' => $drop->id,
                'status' => $status,
            ]),
        };
    }

    /**
     * Get the default RVM method for a company.
     */
    public function getDefaultMethod(Company $company): string
    {
        $settings = $company->settings ?? [];

        return $settings['rvm_method'] ?? 'twilio_amd';
    }

    /**
     * Get the public URL for a template's audio file.
     */
    private function getAudioUrl(RvmTemplate $template): string
    {
        if (str_starts_with($template->audio_path, 'http')) {
            return $template->audio_path;
        }

        return Storage::disk('public')->url($template->audio_path);
    }
}

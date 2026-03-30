<?php

namespace App\Services\Prospector;

use App\Events\Prospector\SmsReceived;
use App\Models\Prospector\Agent;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\SmsMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProspectorSmsService
{
    /**
     * Send an SMS to a contact from the company's main number.
     *
     * Creates the SmsMessage record and sends via Twilio.
     */
    public function sendSms(
        Company $company,
        Contact $contact,
        string $body,
        ?Agent $agent = null,
        ?array $mediaUrls = null,
    ): SmsMessage {
        $fromNumber = $company->getMainNumber();

        if (! $fromNumber) {
            throw new RuntimeException(
                "No main phone number configured for company {$company->name}."
            );
        }

        return $this->sendFromNumber($fromNumber, $contact->phone, $body, $mediaUrls, $company, $contact, $agent);
    }

    /**
     * Send an SMS from a specific phone number.
     *
     * Lower-level method that creates the record and sends via Twilio.
     */
    public function sendFromNumber(
        PhoneNumber $fromNumber,
        string $toNumber,
        string $body,
        ?array $mediaUrls = null,
        ?Company $company = null,
        ?Contact $contact = null,
        ?Agent $agent = null,
    ): SmsMessage {
        $company ??= $fromNumber->company;

        if (! $company) {
            throw new RuntimeException(
                "Phone number {$fromNumber->phone_number} is not assigned to a company."
            );
        }

        // Create the SMS record first
        $smsMessage = SmsMessage::create([
            'company_id' => $company->id,
            'contact_id' => $contact?->id,
            'agent_id' => $agent?->id,
            'direction' => 'outbound',
            'from_number' => $fromNumber->phone_number,
            'to_number' => $toNumber,
            'body' => $body,
            'media_urls' => $mediaUrls,
            'status' => 'queued',
        ]);

        // Send via Twilio
        try {
            $client = $this->getTwilioClient($fromNumber);

            $messageParams = [
                'from' => $fromNumber->phone_number,
                'body' => $body,
                'statusCallback' => route('prospector.webhook.twilio.sms.status'),
            ];

            if ($mediaUrls) {
                $messageParams['mediaUrl'] = $mediaUrls;
            }

            $twilioMessage = $client->messages->create($toNumber, $messageParams);

            $smsMessage->update([
                'twilio_sid' => $twilioMessage->sid,
                'status' => 'sent',
            ]);

            $fromNumber->markUsed();

            Log::info('Prospector SMS sent', [
                'sms_id' => $smsMessage->id,
                'company_id' => $company->id,
                'contact_id' => $contact?->id,
                'from' => $fromNumber->phone_number,
                'to' => $toNumber,
                'twilio_sid' => $twilioMessage->sid,
            ]);
        } catch (\Exception $e) {
            $smsMessage->update(['status' => 'failed']);

            Log::error('Prospector SMS send failed', [
                'sms_id' => $smsMessage->id,
                'company_id' => $company->id,
                'to' => $toNumber,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return $smsMessage;
    }

    /**
     * Process an inbound SMS message.
     *
     * Matches the 'to' number to a PhoneNumber → Company.
     * Matches the 'from' number to a Contact (or leaves null).
     * Creates an SmsMessage record and broadcasts the event.
     */
    public function handleIncoming(
        string $from,
        string $to,
        string $body,
        ?array $mediaUrls = null,
        ?string $twilioSid = null,
    ): SmsMessage {
        // Find the phone number that received this message
        $phoneNumber = PhoneNumber::where('phone_number', $to)->first();

        if (! $phoneNumber || ! $phoneNumber->company_id) {
            Log::warning('Prospector inbound SMS to unknown number', [
                'from' => $from,
                'to' => $to,
                'twilio_sid' => $twilioSid,
            ]);

            throw new RuntimeException("No company found for phone number {$to}.");
        }

        $company = $phoneNumber->company;

        // Try to match the sender to an existing contact
        $contact = Contact::where('company_id', $company->id)
            ->where(function ($query) use ($from) {
                $query->where('phone', $from)
                    ->orWhere('phone_2', $from);
            })
            ->first();

        $smsMessage = SmsMessage::create([
            'company_id' => $company->id,
            'contact_id' => $contact?->id,
            'agent_id' => null,
            'direction' => 'inbound',
            'from_number' => $from,
            'to_number' => $to,
            'body' => $body,
            'media_urls' => $mediaUrls,
            'twilio_sid' => $twilioSid,
            'status' => 'received',
        ]);

        // Update the contact's SMS count
        if ($contact) {
            $contact->increment('total_sms');
        }

        Log::info('Prospector inbound SMS received', [
            'sms_id' => $smsMessage->id,
            'company_id' => $company->id,
            'contact_id' => $contact?->id,
            'from' => $from,
            'to' => $to,
        ]);

        broadcast(new SmsReceived($smsMessage, $company->id));

        return $smsMessage;
    }

    /**
     * Get the SMS conversation thread between a company and contact.
     *
     * @return Collection<int, SmsMessage>
     */
    public function getConversation(Contact $contact, int $limit = 50): Collection
    {
        return SmsMessage::where('company_id', $contact->company_id)
            ->where('contact_id', $contact->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Update SMS status to delivered from Twilio delivery callback.
     */
    public function markDelivered(string $twilioSid): void
    {
        $sms = SmsMessage::where('twilio_sid', $twilioSid)->first();

        if (! $sms) {
            Log::warning('Prospector SMS delivery callback: message not found', ['twilio_sid' => $twilioSid]);

            return;
        }

        $sms->update(['status' => 'delivered']);
    }

    /**
     * Update SMS status to failed from Twilio failure callback.
     */
    public function markFailed(string $twilioSid, ?string $error = null): void
    {
        $sms = SmsMessage::where('twilio_sid', $twilioSid)->first();

        if (! $sms) {
            Log::warning('Prospector SMS failure callback: message not found', ['twilio_sid' => $twilioSid]);

            return;
        }

        $sms->update(['status' => 'failed']);

        Log::warning('Prospector SMS delivery failed', [
            'sms_id' => $sms->id,
            'twilio_sid' => $twilioSid,
            'error' => $error,
        ]);
    }

    /**
     * Get a Twilio client from a phone number's associated account.
     */
    private function getTwilioClient(PhoneNumber $phoneNumber): \Twilio\Rest\Client
    {
        $phoneNumber->loadMissing('twilioAccount');

        $account = $phoneNumber->twilioAccount;

        if (! $account) {
            throw new RuntimeException(
                "Phone number {$phoneNumber->phone_number} has no associated Twilio account."
            );
        }

        return $account->getClient();
    }
}

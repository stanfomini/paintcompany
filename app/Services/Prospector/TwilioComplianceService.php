<?php

namespace App\Services\Prospector;

use App\Models\Prospector\MessagingService;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TwilioComplianceService
{
    /**
     * Get all messaging services for a Twilio account.
     */
    public function getMessagingServices(TwilioAccount $account): Collection
    {
        return MessagingService::where('twilio_account_id', $account->id)
            ->with('company')
            ->orderBy('friendly_name')
            ->get();
    }

    /**
     * Get compliance summary for a Twilio account.
     *
     * @return array{total: int, fully_compliant: int, brand_approved: int, campaigns_verified: int}
     */
    public function getComplianceSummary(TwilioAccount $account): array
    {
        $services = MessagingService::where('twilio_account_id', $account->id)->get();

        return [
            'total' => $services->count(),
            'fully_compliant' => $services->filter->isFullyCompliant()->count(),
            'brand_approved' => $services->filter->isBrandApproved()->count(),
            'campaigns_verified' => $services->filter->isCampaignVerified()->count(),
        ];
    }

    /**
     * Create a messaging service on Twilio and store locally.
     *
     * @throws \RuntimeException
     */
    public function createMessagingService(
        TwilioAccount $account,
        string $friendlyName,
        ?int $companyId = null,
    ): MessagingService {
        $client = $account->getClient();

        try {
            $twilioService = $client->messaging->v1->services->create($friendlyName, [
                'inboundRequestUrl' => route('prospector.webhook.twilio.sms.incoming'),
                'inboundMethod' => 'POST',
                'statusCallback' => route('prospector.webhook.twilio.sms.status'),
                'useInboundWebhookOnNumber' => false,
                'areaCodeGeomatch' => true,
                'stickySender' => true,
            ]);

            $record = MessagingService::create([
                'twilio_account_id' => $account->id,
                'company_id' => $companyId,
                'twilio_sid' => $twilioService->sid,
                'friendly_name' => $friendlyName,
                'status' => 'active',
            ]);

            Log::info('Created Twilio Messaging Service', [
                'messaging_service_id' => $record->id,
                'twilio_sid' => $twilioService->sid,
                'name' => $friendlyName,
            ]);

            return $record;
        } catch (\Exception $e) {
            Log::error('Failed to create Twilio Messaging Service', [
                'name' => $friendlyName,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException("Failed to create messaging service: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Add a phone number to a messaging service on Twilio.
     */
    public function addNumberToService(MessagingService $messagingService, PhoneNumber $number): bool
    {
        if (! $messagingService->twilio_sid || ! $number->twilio_sid) {
            return false;
        }

        $messagingService->load('twilioAccount');
        $client = $messagingService->twilioAccount->getClient();

        try {
            $client->messaging->v1->services($messagingService->twilio_sid)
                ->phoneNumbers
                ->create($number->twilio_sid);

            $messagingService->increment('phone_number_count');

            Log::info('Added number to messaging service', [
                'messaging_service_id' => $messagingService->id,
                'phone_number' => $number->phone_number,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to add number to messaging service', [
                'messaging_service_id' => $messagingService->id,
                'phone_number' => $number->phone_number,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Refresh brand and campaign statuses from Twilio.
     */
    public function refreshStatus(MessagingService $messagingService): MessagingService
    {
        $messagingService->load('twilioAccount');
        $client = $messagingService->twilioAccount->getClient();

        // Refresh brand status
        if ($messagingService->brand_sid) {
            try {
                $brand = $client->messaging->v1->brandRegistrations($messagingService->brand_sid)->fetch();
                $messagingService->brand_status = $brand->status;
            } catch (\Exception $e) {
                Log::warning('Failed to fetch brand status', [
                    'brand_sid' => $messagingService->brand_sid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Refresh campaign status
        if ($messagingService->campaign_sid && $messagingService->twilio_sid) {
            try {
                $campaign = $client->messaging->v1
                    ->services($messagingService->twilio_sid)
                    ->usAppToPerson($messagingService->campaign_sid)
                    ->fetch();
                $messagingService->campaign_status = $campaign->campaignStatus;
            } catch (\Exception $e) {
                Log::warning('Failed to fetch campaign status', [
                    'campaign_sid' => $messagingService->campaign_sid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $messagingService->save();

        return $messagingService;
    }
}

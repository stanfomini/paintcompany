<?php

namespace App\Services\Prospector;

use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;

class TwilioSyncService
{
    /**
     * Sync all phone numbers from a Twilio account.
     *
     * Pulls IncomingPhoneNumber resources from Twilio API,
     * upserts into prospector_phone_numbers by twilio_sid,
     * and marks removed numbers as inactive.
     *
     * @param  Client|null  $client  Optional Twilio client override (for testing)
     * @return array{created: int, updated: int, removed: int}
     */
    public function syncNumbers(TwilioAccount $account, ?object $client = null): array
    {
        $client ??= $account->getClient();

        try {
            $numbers = $client->incomingPhoneNumbers->read([], 1000);
        } catch (\Exception $e) {
            Log::error('Twilio sync failed: unable to fetch phone numbers', [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $existingSids = PhoneNumber::where('twilio_account_id', $account->id)
            ->pluck('twilio_sid')
            ->toArray();

        $syncedSids = [];
        $created = 0;
        $updated = 0;

        foreach ($numbers as $number) {
            $syncedSids[] = $number->sid;

            $areaCode = $this->extractAreaCode($number->phoneNumber);

            $phoneNumber = PhoneNumber::updateOrCreate(
                ['twilio_sid' => $number->sid],
                [
                    'twilio_account_id' => $account->id,
                    'phone_number' => $number->phoneNumber,
                    'friendly_name' => $number->friendlyName,
                    'area_code' => $areaCode,
                    'capabilities' => [
                        'voice' => $number->capabilities['voice'] ?? false,
                        'sms' => $number->capabilities['sms'] ?? false,
                        'mms' => $number->capabilities['mms'] ?? false,
                        'fax' => $number->capabilities['fax'] ?? false,
                    ],
                    'is_active' => true,
                ]
            );

            if ($phoneNumber->wasRecentlyCreated) {
                $created++;
            } else {
                $updated++;
            }
        }

        // Mark numbers no longer in Twilio as inactive (preserve history, don't delete)
        $removedSids = array_diff($existingSids, $syncedSids);
        $removed = 0;

        if (! empty($removedSids)) {
            $removed = PhoneNumber::where('twilio_account_id', $account->id)
                ->whereIn('twilio_sid', $removedSids)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }

        $account->update(['last_synced_at' => now()]);

        Log::info('Twilio phone number sync completed', [
            'account_id' => $account->id,
            'account_name' => $account->name,
            'created' => $created,
            'updated' => $updated,
            'removed' => $removed,
        ]);

        return compact('created', 'updated', 'removed');
    }

    /**
     * Sync a single phone number by SID.
     *
     * Useful for webhook-triggered updates when a number
     * is purchased, released, or modified in Twilio.
     *
     * @param  Client|null  $client  Optional Twilio client override (for testing)
     */
    public function syncSingleNumber(TwilioAccount $account, string $sid, ?object $client = null): PhoneNumber
    {
        $client ??= $account->getClient();

        try {
            $number = $client->incomingPhoneNumbers($sid)->fetch();
        } catch (\Exception $e) {
            Log::error('Twilio single number sync failed', [
                'account_id' => $account->id,
                'sid' => $sid,
                'error' => $e->getMessage(),
            ]);

            // If the number no longer exists in Twilio, mark it inactive locally
            if ($this->isNotFoundError($e)) {
                $existing = PhoneNumber::where('twilio_sid', $sid)->first();

                if ($existing) {
                    $existing->update(['is_active' => false]);

                    return $existing;
                }
            }

            throw $e;
        }

        $areaCode = $this->extractAreaCode($number->phoneNumber);

        return PhoneNumber::updateOrCreate(
            ['twilio_sid' => $number->sid],
            [
                'twilio_account_id' => $account->id,
                'phone_number' => $number->phoneNumber,
                'friendly_name' => $number->friendlyName,
                'area_code' => $areaCode,
                'capabilities' => [
                    'voice' => $number->capabilities['voice'] ?? false,
                    'sms' => $number->capabilities['sms'] ?? false,
                    'mms' => $number->capabilities['mms'] ?? false,
                    'fax' => $number->capabilities['fax'] ?? false,
                ],
                'is_active' => true,
            ]
        );
    }

    /**
     * Reactivate a previously deactivated number if it reappears in Twilio.
     *
     * @param  Client|null  $clientOverride  Optional Twilio client override (for testing)
     */
    public function reactivateNumber(PhoneNumber $number, ?object $clientOverride = null): PhoneNumber
    {
        if ($number->is_active) {
            return $number;
        }

        // Verify the number still exists in Twilio before reactivating
        $account = $number->twilioAccount;
        $client = $clientOverride ?? $account->getClient();

        try {
            $client->incomingPhoneNumbers($number->twilio_sid)->fetch();
        } catch (\Exception $e) {
            Log::warning('Cannot reactivate number: not found in Twilio', [
                'phone_number_id' => $number->id,
                'twilio_sid' => $number->twilio_sid,
            ]);

            throw new \RuntimeException(
                "Cannot reactivate number {$number->phone_number}: no longer exists in Twilio."
            );
        }

        $number->update(['is_active' => true]);

        return $number->refresh();
    }

    /**
     * Extract the 3-digit area code from an E.164 phone number.
     *
     * Handles +1XXXXXXXXXX format (US/Canada).
     */
    private function extractAreaCode(string $phoneNumber): string
    {
        $digits = preg_replace('/\D/', '', $phoneNumber);

        // US/Canada: 11 digits starting with 1
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return substr($digits, 1, 3);
        }

        // 10 digits (no country code)
        if (strlen($digits) === 10) {
            return substr($digits, 0, 3);
        }

        // Fallback: skip +1 prefix from E.164
        if (str_starts_with($phoneNumber, '+1') && strlen($digits) >= 4) {
            return substr($digits, 1, 3);
        }

        return substr($digits, 0, 3);
    }

    /**
     * Check if a Twilio exception indicates a 404 Not Found.
     */
    private function isNotFoundError(\Exception $e): bool
    {
        // Twilio SDK throws RestException with status 404
        if (method_exists($e, 'getStatusCode')) {
            return $e->getStatusCode() === 404;
        }

        // Fallback: check message content
        return str_contains($e->getMessage(), '20404')
            || str_contains($e->getMessage(), 'not found');
    }
}

<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\PhoneNumber;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class NumberSelectionService
{
    /**
     * Select the best outbound phone number for a call.
     *
     * Priority:
     * 1. Campaign has a specific phone_number_id assigned → use that
     * 2. Campaign uses local presence → match contact's area code
     * 3. Fall back to company's main number
     * 4. No main number → throw exception
     *
     * @throws RuntimeException
     */
    public function selectNumber(Company $company, Contact $contact, ?Campaign $campaign = null): PhoneNumber
    {
        // 1. Campaign-specific number
        if ($campaign && $campaign->phone_number_id) {
            $number = $campaign->phoneNumber;

            if ($number && $number->is_active) {
                Log::debug('Number selected: campaign-specific', [
                    'phone_number_id' => $number->id,
                    'phone_number' => $number->phone_number,
                    'campaign_id' => $campaign->id,
                ]);

                return $number;
            }

            Log::warning('Campaign phone number is inactive or missing, falling through', [
                'campaign_id' => $campaign->id,
                'phone_number_id' => $campaign->phone_number_id,
            ]);
        }

        // 2. Local presence matching
        if ($campaign && $campaign->use_local_presence) {
            $areaCode = $contact->getAreaCode();
            $localNumber = $this->findLocalPresenceMatch($company, $areaCode);

            if ($localNumber) {
                Log::debug('Number selected: local presence match', [
                    'phone_number_id' => $localNumber->id,
                    'phone_number' => $localNumber->phone_number,
                    'area_code' => $areaCode,
                ]);

                return $localNumber;
            }

            Log::debug('No local presence match for area code, falling to main', [
                'area_code' => $areaCode,
                'company_id' => $company->id,
            ]);
        }

        // 3. Company main number
        $mainNumber = $company->getMainNumber();

        if ($mainNumber && $mainNumber->is_active) {
            Log::debug('Number selected: company main number', [
                'phone_number_id' => $mainNumber->id,
                'phone_number' => $mainNumber->phone_number,
            ]);

            return $mainNumber;
        }

        // 4. No number available
        throw new RuntimeException(
            "No active outbound phone number available for company {$company->name} (ID: {$company->id}). "
            .'Assign a main phone number or configure campaign-specific numbers.'
        );
    }

    /**
     * Preview which number would be used for a contact without marking it as used.
     *
     * Same logic as selectNumber() but read-only — useful for showing
     * area code badges on queue cards before dialing.
     */
    public function previewNumberForContact(Contact $contact, Company $company, ?Campaign $campaign = null): ?PhoneNumber
    {
        try {
            return $this->selectNumber($company, $contact, $campaign);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Find a local presence phone number matching the given area code.
     *
     * Searches for shadow/local_presence numbers assigned to the company
     * that share the contact's area code.
     */
    public function findLocalPresenceMatch(Company $company, string $areaCode): ?PhoneNumber
    {
        return PhoneNumber::where('company_id', $company->id)
            ->where('is_active', true)
            ->where('area_code', $areaCode)
            ->whereIn('role', ['shadow', 'local_presence'])
            ->first();
    }
}

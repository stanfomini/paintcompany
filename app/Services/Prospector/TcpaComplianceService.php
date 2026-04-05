<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class TcpaComplianceService
{
    /**
     * Check if a contact can be called right now.
     *
     * Validates all TCPA rules. Returns false if any rule fails.
     */
    public function canCall(Contact $contact, Company $company, ?Campaign $campaign = null): bool
    {
        return empty($this->getCallBlockReasons($contact, $company, $campaign));
    }

    /**
     * Get all reasons why a call to this contact would be blocked.
     *
     * Returns an empty array if the contact can be called.
     *
     * @return array<string>
     */
    public function getCallBlockReasons(Contact $contact, Company $company, ?Campaign $campaign = null): array
    {
        $reasons = [];

        if ($this->isDnc($contact)) {
            $reasons[] = 'Contact is on the Do Not Call list.';
        }

        if (! $this->isInCallingHours($contact, $company)) {
            $reasons[] = 'Outside TCPA calling hours (8am-9pm in contact timezone).';
        }

        if ($campaign && $this->hasExceededAttempts($contact, $campaign)) {
            $maxAttempts = app(SettingsService::class)->get('tcpa.max_attempts_per_contact', 3);
            $reasons[] = "Contact has exceeded maximum dial attempts ({$maxAttempts}) for this campaign.";
        }

        if ($this->isWithinCooldown($contact)) {
            $minHours = app(SettingsService::class)->get('tcpa.min_hours_between_attempts', 4);
            $reasons[] = "Contact was called within the last {$minHours} hours (cooldown period).";
        }

        if (! empty($reasons)) {
            Log::debug('TCPA compliance check failed', [
                'contact_id' => $contact->id,
                'company_id' => $company->id,
                'campaign_id' => $campaign?->id,
                'reasons' => $reasons,
            ]);
        }

        return $reasons;
    }

    /**
     * Check if the current time is within TCPA calling hours for the contact.
     *
     * TCPA requires calls between 8am-9pm in the contact's local time.
     * Falls back to company timezone if contact timezone is not set.
     */
    public function isInCallingHours(Contact $contact, Company $company): bool
    {
        $timezone = $contact->timezone ?? $company->timezone ?? 'America/Denver';

        try {
            $now = Carbon::now($timezone);
        } catch (\Exception $e) {
            // Invalid timezone — default to company or Denver
            $now = Carbon::now($company->timezone ?? 'America/Denver');
        }

        $start = app(SettingsService::class)->get('tcpa.calling_hours_start', '08:00');
        $end = app(SettingsService::class)->get('tcpa.calling_hours_end', '21:00');

        $startTime = Carbon::parse($start, $now->timezone);
        $endTime = Carbon::parse($end, $now->timezone);

        return $now->between($startTime, $endTime);
    }

    /**
     * Check if the contact has exceeded the max dial attempts for a campaign.
     */
    public function hasExceededAttempts(Contact $contact, Campaign $campaign): bool
    {
        $maxAttempts = app(SettingsService::class)->get('tcpa.max_attempts_per_contact', 3);

        $entry = CampaignContact::where('campaign_id', $campaign->id)
            ->where('contact_id', $contact->id)
            ->first();

        if (! $entry) {
            return false;
        }

        return $entry->attempts >= $maxAttempts;
    }

    /**
     * Check if the contact is within the cooldown period between calls.
     *
     * Uses the contact's last_contacted_at timestamp and the
     * configured minimum hours between attempts.
     */
    public function isWithinCooldown(Contact $contact): bool
    {
        if (! $contact->last_contacted_at) {
            return false;
        }

        $minHours = app(SettingsService::class)->get('tcpa.min_hours_between_attempts', 4);

        return $contact->last_contacted_at->addHours($minHours)->isFuture();
    }

    /**
     * Check if the contact is on the Do Not Call list.
     */
    public function isDnc(Contact $contact): bool
    {
        return $contact->do_not_call;
    }
}

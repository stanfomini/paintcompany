<?php

namespace App\Services\Prospector;

use App\Events\Prospector\QueueUpdated;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookIntakeService
{
    /**
     * Process an inbound lead webhook payload.
     *
     * Creates or updates a contact (dedup by phone within company).
     * Optionally enrolls in a campaign if campaign_ulid provided.
     *
     * @param  array{
     *   first_name: string,
     *   last_name?: string,
     *   phone: string,
     *   phone_2?: string,
     *   email?: string,
     *   address?: string,
     *   city?: string,
     *   state?: string,
     *   zip?: string,
     *   timezone?: string,
     *   tags?: array<string>,
     *   custom_fields?: array<string, mixed>,
     *   external_id?: string,
     *   priority?: int,
     *   campaign_ulid?: string,
     * } $data
     * @return array{contact: Contact, created: bool, campaign_enrolled: bool}
     */
    public function processLead(Company $company, array $data): array
    {
        $phone = $this->formatPhone($data['phone']);

        // Check DNC list — do not create or update do_not_call contacts
        $dncContact = Contact::where('company_id', $company->id)
            ->where('phone', $phone)
            ->where('do_not_call', true)
            ->first();

        if ($dncContact) {
            Log::info('Webhook lead rejected: contact is on DNC list', [
                'company_id' => $company->id,
                'phone' => $phone,
            ]);

            return [
                'contact' => $dncContact,
                'created' => false,
                'campaign_enrolled' => false,
            ];
        }

        // Use ContactIntakeService for creation with pipeline stage + broadcasting
        $intake = app(ContactIntakeService::class);
        $result = $intake->findOrCreate($company, $phone, [
            'source' => 'webhook',
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'] ?? null,
            'phone_2' => isset($data['phone_2']) ? $this->formatPhone($data['phone_2']) : null,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip' => $data['zip'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'tags' => $data['tags'] ?? null,
            'custom_fields' => $data['custom_fields'] ?? null,
            'external_id' => $data['external_id'] ?? null,
            'priority' => $data['priority'] ?? 0,
        ]);

        $contact = $result->contact;
        $created = $result->wasCreated;
        $campaignEnrolled = false;

        // If existing, update non-null fields
        if (! $created) {
            $updateData = $this->buildUpdateData($data, $contact);
            $contact->update($updateData);
            $contact->refresh();
        }

        // Enroll in campaign if campaign_ulid provided
        if (! empty($data['campaign_ulid'])) {
            $campaign = Campaign::where('company_id', $company->id)
                ->where('ulid', $data['campaign_ulid'])
                ->active()
                ->first();

            if ($campaign) {
                $campaignEnrolled = $this->enrollInCampaign($contact, $campaign);
            }
        }

        // Auto-enroll in default campaign if new contact and no explicit campaign enrollment
        if ($created && ! $campaignEnrolled && $company->default_campaign_id) {
            $defaultCampaign = Campaign::find($company->default_campaign_id);
            if ($defaultCampaign && $defaultCampaign->isActive()) {
                $campaignEnrolled = $this->enrollAtFront($contact, $defaultCampaign);
            }
        }

        // NewLeadArrived is broadcast by ContactIntakeService on creation

        Log::info('Webhook lead processed', [
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'created' => $created,
            'campaign_enrolled' => $campaignEnrolled,
        ]);

        return [
            'contact' => $contact,
            'created' => $created,
            'campaign_enrolled' => $campaignEnrolled,
        ];
    }

    /**
     * Validate webhook payload.
     *
     * Returns array of validation errors (empty = valid).
     *
     * @return array<string>
     */
    public function validatePayload(array $data): array
    {
        $errors = [];

        if (empty($data['first_name'])) {
            $errors[] = 'first_name is required.';
        }

        if (empty($data['phone'])) {
            $errors[] = 'phone is required.';
        } elseif (! $this->isValidPhone($data['phone'])) {
            $errors[] = 'phone must contain at least 10 digits.';
        }

        if (isset($data['priority']) && ! is_int($data['priority'])) {
            $errors[] = 'priority must be an integer.';
        }

        if (isset($data['tags']) && ! is_array($data['tags'])) {
            $errors[] = 'tags must be an array.';
        }

        if (isset($data['custom_fields']) && ! is_array($data['custom_fields'])) {
            $errors[] = 'custom_fields must be an array.';
        }

        return $errors;
    }

    /**
     * Format phone number to E.164.
     *
     * Handles: (720) 555-1234, 720-555-1234, 7205551234, +17205551234
     */
    public function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        // Already has country code
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        // 10-digit US number — prepend +1
        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        // Already in full E.164 with +
        if (str_starts_with($phone, '+') && strlen($digits) >= 11) {
            return '+'.$digits;
        }

        // Fallback: prepend +1 if we have enough digits
        if (strlen($digits) >= 10) {
            return '+1'.substr($digits, -10);
        }

        // Return as-is with + prefix for short/international numbers
        return '+'.$digits;
    }

    /**
     * Enroll a contact in a campaign.
     *
     * Skips if already enrolled. Sets sort_order based on existing max + 1.
     */
    public function enrollInCampaign(Contact $contact, Campaign $campaign): bool
    {
        // Check not already enrolled
        $alreadyEnrolled = CampaignContact::where('campaign_id', $campaign->id)
            ->where('contact_id', $contact->id)
            ->exists();

        if ($alreadyEnrolled) {
            return false;
        }

        return DB::transaction(function () use ($contact, $campaign): bool {
            // Determine next sort_order
            $maxSortOrder = CampaignContact::where('campaign_id', $campaign->id)
                ->max('sort_order') ?? 0;

            // Create campaign_contact pivot
            CampaignContact::create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'sort_order' => $maxSortOrder + 1,
                'status' => 'queued',
                'added_at' => now(),
            ]);

            // Increment campaign.contact_count
            $campaign->increment('contact_count');

            return true;
        });
    }

    /**
     * Enroll a contact at the front of a campaign queue.
     *
     * Shifts all existing queued contacts' sort_order up by 1,
     * then inserts the new contact at sort_order 0.
     */
    public function enrollAtFront(Contact $contact, Campaign $campaign): bool
    {
        $alreadyEnrolled = CampaignContact::where('campaign_id', $campaign->id)
            ->where('contact_id', $contact->id)
            ->exists();

        if ($alreadyEnrolled) {
            return false;
        }

        return DB::transaction(function () use ($contact, $campaign): bool {
            // Shift all existing contacts' sort_order up by 1
            CampaignContact::where('campaign_id', $campaign->id)
                ->increment('sort_order');

            // Insert new contact at front
            CampaignContact::create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'sort_order' => 0,
                'status' => 'queued',
                'added_at' => now(),
            ]);

            // Mark new lead as priority
            CampaignContact::where('campaign_id', $campaign->id)
                ->where('contact_id', $contact->id)
                ->update(['is_priority' => true, 'priority_reason' => 'new_lead']);

            $campaign->increment('contact_count');

            broadcast(new QueueUpdated($contact, $campaign, 'added'));

            return true;
        });
    }

    /**
     * Move a queued contact to the front of their campaign queue.
     *
     * Used when an inbound SMS is received from a queued contact.
     */
    public function pushToFront(Contact $contact, CampaignContact $queueEntry): void
    {
        DB::transaction(function () use ($queueEntry): void {
            $currentOrder = $queueEntry->sort_order;

            // Shift contacts before this one down by 1
            CampaignContact::where('campaign_id', $queueEntry->campaign_id)
                ->where('sort_order', '<', $currentOrder)
                ->increment('sort_order');

            $queueEntry->update(['sort_order' => 0]);
        });

        $campaign = Campaign::find($queueEntry->campaign_id);
        if ($campaign) {
            broadcast(new QueueUpdated($contact, $campaign, 'priority_bump'));
        }
    }

    /**
     * Build the update data array for an existing contact.
     *
     * Only updates fields that are provided and non-null.
     * Tags are merged (not overwritten).
     */
    private function buildUpdateData(array $data, Contact $existing): array
    {
        $updateData = [];

        $simpleFields = [
            'first_name', 'last_name', 'email', 'address',
            'city', 'state', 'zip', 'timezone', 'external_id',
        ];

        foreach ($simpleFields as $field) {
            if (isset($data[$field]) && $data[$field] !== null) {
                $updateData[$field] = $data[$field];
            }
        }

        // Format phone_2 if provided
        if (! empty($data['phone_2'])) {
            $updateData['phone_2'] = $this->formatPhone($data['phone_2']);
        }

        // Priority: overwrite if provided
        if (isset($data['priority'])) {
            $updateData['priority'] = $data['priority'];
        }

        // Tags: merge with existing, don't overwrite
        if (! empty($data['tags'])) {
            $existingTags = $existing->tags ?? [];
            $updateData['tags'] = array_values(array_unique(array_merge($existingTags, $data['tags'])));
        }

        // Custom fields: merge with existing
        if (! empty($data['custom_fields'])) {
            $existingFields = $existing->custom_fields ?? [];
            $updateData['custom_fields'] = array_merge($existingFields, $data['custom_fields']);
        }

        return $updateData;
    }

    /**
     * Public wrapper for phone validation.
     */
    public function isValidPhonePublic(string $phone): bool
    {
        return $this->isValidPhone($phone);
    }

    /**
     * Check if a phone number has at least 10 digits.
     */
    private function isValidPhone(string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) >= 10;
    }
}

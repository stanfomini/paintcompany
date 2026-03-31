<?php

namespace App\Services\Prospector;

use App\Events\Prospector\NewLeadArrived;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\PipelineStage;
use App\Models\Prospector\SmsMessage;

class ContactIntakeService
{
    /**
     * Find or create a contact within a company, deduplicating by phone.
     *
     * On creation: assigns default pipeline stage, broadcasts NewLeadArrived,
     * and backfills any orphaned SMS messages from this phone number.
     */
    public function findOrCreate(Company $company, string $phone, array $attributes = []): ContactIntakeResult
    {
        $phone = app(WebhookIntakeService::class)->formatPhone($phone);

        // Dedup by phone OR phone_2 within company
        $existing = Contact::where('company_id', $company->id)
            ->where(function ($q) use ($phone) {
                $q->where('phone', $phone)->orWhere('phone_2', $phone);
            })
            ->first();

        if ($existing) {
            return new ContactIntakeResult(contact: $existing, wasCreated: false);
        }

        $defaultStageId = $this->getDefaultPipelineStageId();

        $contact = Contact::create(array_merge([
            'company_id' => $company->id,
            'phone' => $phone,
            'first_name' => $attributes['first_name'] ?? 'Unknown',
            'pipeline_stage_id' => $defaultStageId,
        ], collect($attributes)->only([
            'first_name', 'last_name', 'email', 'address', 'city', 'state',
            'zip', 'timezone', 'tags', 'custom_fields', 'external_id',
            'priority', 'source', 'phone_2',
        ])->all()));

        // Backfill orphaned SMS messages from this phone number
        SmsMessage::where('company_id', $company->id)
            ->whereNull('contact_id')
            ->where('from_number', $phone)
            ->update(['contact_id' => $contact->id]);

        // Count any backfilled SMS
        $backfilledCount = SmsMessage::where('contact_id', $contact->id)
            ->where('direction', 'inbound')
            ->count();

        if ($backfilledCount > 0) {
            $contact->update([
                'total_sms' => $backfilledCount,
                'has_unread_sms' => true,
            ]);
        }

        broadcast(new NewLeadArrived($contact, $company));

        return new ContactIntakeResult(contact: $contact, wasCreated: true);
    }

    /**
     * Get the first stage of the default pipeline.
     */
    public function getDefaultPipelineStageId(): ?int
    {
        return PipelineStage::whereHas('pipeline', fn ($q) => $q->where('is_default', true))
            ->orderBy('sort_order')
            ->first()?->id;
    }
}

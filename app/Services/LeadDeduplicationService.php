<?php

namespace App\Services;

use App\DTOs\LeadResolutionResult;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\MessengerConversation;

class LeadDeduplicationService
{
    /**
     * Find an existing customer by PSID, email, or phone.
     */
    public function findExistingCustomer(array $customerData, ?string $psid = null): ?Customer
    {
        // 1. PSID match (most reliable for messenger)
        if ($psid) {
            $conversation = MessengerConversation::where('facebook_psid', $psid)
                ->whereNotNull('customer_id')
                ->latest()
                ->first();

            if ($conversation?->customer_id) {
                return Customer::find($conversation->customer_id);
            }
        }

        // 2. Email match (case-insensitive)
        if (! empty($customerData['email'])) {
            $customer = Customer::whereRaw('LOWER(email) = ?', [strtolower($customerData['email'])])->first();
            if ($customer) {
                return $customer;
            }
        }

        // 3. Phone match (normalized digits-only)
        if (! empty($customerData['phone'])) {
            $normalizedPhone = $this->normalizePhone($customerData['phone']);
            if (strlen($normalizedPhone) >= 7) {
                $customer = Customer::get()->first(function ($c) use ($normalizedPhone) {
                    return $c->phone && $this->normalizePhone($c->phone) === $normalizedPhone;
                });
                if ($customer) {
                    return $customer;
                }
            }
        }

        return null;
    }

    /**
     * Find an active (pre-sale) deal for a customer, regardless of lead source.
     */
    public function findActiveDeal(Customer $customer): ?Deal
    {
        return Deal::where('customer_id', $customer->id)
            ->whereNotIn('stage', Deal::TERMINAL_STAGES)
            ->latest()
            ->first();
    }

    /**
     * Resolve or create customer + deal + estimate, deduplicating across all lead sources.
     *
     * @param  array<string, mixed>  $customerData  Customer fields (first_name, last_name, email, phone, etc.)
     * @param  array<string, mixed>  $dealData  Deal fields (title, description, lead_source, assigned_to, etc.)
     * @param  array<string, mixed>  $options  Optional: psid, create_estimate (bool), estimate_data (array), source_label (string)
     */
    public function resolveOrCreate(array $customerData, array $dealData, array $options = []): LeadResolutionResult
    {
        $psid = $options['psid'] ?? null;
        $createEstimate = $options['create_estimate'] ?? false;
        $estimateData = $options['estimate_data'] ?? [];
        $sourceLabel = $options['source_label'] ?? ($dealData['lead_source'] ?? 'Unknown');

        // 1. Find existing customer
        $customer = $this->findExistingCustomer($customerData, $psid);
        $customerWasExisting = $customer !== null;

        if (! $customer) {
            // New customer
            $customer = Customer::create($customerData);
            app(CustomerDuplicateService::class)->checkAndFlagDuplicates($customer);

            // New customer = new deal always
            $dealData['customer_id'] = $customer->id;
            $deal = Deal::create($dealData);

            $estimate = null;
            if ($createEstimate) {
                $estimate = Estimate::create(array_merge([
                    'deal_id' => $deal->id,
                    'created_by' => $dealData['created_by'] ?? null,
                    'title' => $deal->title,
                    'status' => 'draft',
                    'subtotal' => 0,
                    'tax_amount' => 0,
                    'total' => 0,
                ], $estimateData));
            }

            return new LeadResolutionResult(
                customer: $customer,
                deal: $deal,
                estimate: $estimate,
                customerWasExisting: false,
                dealWasExisting: false,
                estimateWasExisting: false,
            );
        }

        // Existing customer — fill missing fields only (never overwrite)
        $this->fillMissingCustomerFields($customer, $customerData);

        // Check for active deal
        $existingDeal = $this->findActiveDeal($customer);

        if ($existingDeal) {
            // Merge into existing deal
            $this->mergeDealData($existingDeal, $dealData, $sourceLabel);

            $estimate = $existingDeal->estimates()->latest()->first();

            return new LeadResolutionResult(
                customer: $customer,
                deal: $existingDeal,
                estimate: $estimate,
                customerWasExisting: true,
                dealWasExisting: true,
                estimateWasExisting: $estimate !== null,
            );
        }

        // No active deal — create new one
        $dealData['customer_id'] = $customer->id;
        $deal = Deal::create($dealData);

        $estimate = null;
        if ($createEstimate) {
            $estimate = Estimate::create(array_merge([
                'deal_id' => $deal->id,
                'created_by' => $dealData['created_by'] ?? null,
                'title' => $deal->title,
                'status' => 'draft',
                'subtotal' => 0,
                'tax_amount' => 0,
                'total' => 0,
            ], $estimateData));
        }

        return new LeadResolutionResult(
            customer: $customer,
            deal: $deal,
            estimate: $estimate,
            customerWasExisting: true,
            dealWasExisting: false,
            estimateWasExisting: false,
        );
    }

    /**
     * Merge new lead data into an existing deal.
     * Appends description, fills nulls, logs activity.
     */
    public function mergeDealData(Deal $deal, array $newData, string $sourceLabel): void
    {
        $updatedFields = [];

        // Append description
        if (! empty($newData['description'])) {
            if ($deal->description) {
                $deal->description .= "\n\n---\nUpdated from {$sourceLabel}:\n".$newData['description'];
            } else {
                $deal->description = $newData['description'];
            }
            $updatedFields[] = 'description';
        }

        // Fill nulls only
        $fillableNullFields = ['expected_start_date', 'assigned_to', 'property_id', 'agent_id'];
        foreach ($fillableNullFields as $field) {
            if (isset($newData[$field]) && $deal->$field === null) {
                $deal->$field = $newData[$field];
                $updatedFields[] = $field;
            }
        }

        // Always update appointment_booking_id if provided
        if (! empty($newData['appointment_booking_id'])) {
            $deal->appointment_booking_id = $newData['appointment_booking_id'];
            $updatedFields[] = 'appointment_booking_id';
        }

        // Always update origin references if provided and not set
        if (! empty($newData['origin_door_knock_id']) && ! $deal->origin_door_knock_id) {
            $deal->origin_door_knock_id = $newData['origin_door_knock_id'];
            $updatedFields[] = 'origin_door_knock_id';
        }
        if (! empty($newData['origin_vendor_id']) && ! $deal->origin_vendor_id) {
            $deal->origin_vendor_id = $newData['origin_vendor_id'];
            $updatedFields[] = 'origin_vendor_id';
        }

        // Set merge tracking
        $deal->last_merged_at = now();
        $deal->last_merged_source = $sourceLabel;
        $deal->save();

        // Log activity
        $fieldList = ! empty($updatedFields) ? implode(', ', $updatedFields) : 'no new fields';
        Activity::create([
            'user_id' => $deal->assigned_to ?? $deal->created_by,
            'assigned_to' => $deal->assigned_to ?? $deal->created_by,
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'type' => 'note',
            'title' => "Lead auto-merged from {$sourceLabel}",
            'description' => "Updated: {$fieldList}",
            'is_completed' => true,
        ]);
    }

    /**
     * Fill missing (null/empty) customer fields without overwriting existing data.
     */
    protected function fillMissingCustomerFields(Customer $customer, array $newData): void
    {
        $fillableFields = ['phone', 'address', 'city', 'state', 'zip', 'first_name', 'last_name'];
        $updates = [];

        foreach ($fillableFields as $field) {
            if (! empty($newData[$field]) && empty($customer->$field)) {
                $updates[$field] = $newData[$field];
            }
        }

        if (! empty($updates)) {
            $customer->update($updates);
        }
    }

    /**
     * Normalize phone number by stripping non-digits.
     */
    protected function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }
}

<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\WebhookEndpoint;
use App\Models\WebhookLog;

class WebhookLeadProcessingService
{
    public function process(WebhookEndpoint $endpoint, array $payload, ?string $ipAddress = null): WebhookLog
    {
        $log = WebhookLog::create([
            'webhook_endpoint_id' => $endpoint->id,
            'payload' => $payload,
            'status' => 'received',
            'ip_address' => $ipAddress,
        ]);

        try {
            $customerData = [];
            $dealData = [];

            foreach ($endpoint->fieldMappings()->active()->ordered()->get() as $mapping) {
                $value = data_get($payload, $mapping->source_path);
                $value = $mapping->applyTransform($value);

                if ($mapping->target_model === 'customer') {
                    $customerData[$mapping->target_field] = $value;
                } else {
                    $dealData[$mapping->target_field] = $value;
                }
            }

            // Duplicate check by external_id
            $externalId = $dealData['external_id'] ?? null;
            if ($externalId) {
                $existingDeal = Deal::where('external_id', $externalId)
                    ->where('lead_source_id', $endpoint->lead_source_id)
                    ->first();

                if ($existingDeal) {
                    $log->update(['status' => 'duplicate', 'deal_id' => $existingDeal->id]);

                    return $log;
                }
            }

            $dedup = app(LeadDeduplicationService::class);
            $result = $dedup->resolveOrCreate(
                customerData: array_merge([
                    'first_name' => $customerData['first_name'] ?? 'Unknown',
                    'last_name' => $customerData['last_name'] ?? '',
                    'email' => $customerData['email'] ?? null,
                    'phone' => isset($customerData['phone']) ? preg_replace('/[^0-9]/', '', $customerData['phone']) : null,
                    'address' => $customerData['address'] ?? null,
                    'city' => $customerData['city'] ?? null,
                    'state' => $customerData['state'] ?? null,
                    'zip' => $customerData['zip'] ?? null,
                    'source' => $endpoint->leadSource?->slug,
                    'lead_source_id' => $endpoint->lead_source_id,
                    'created_by' => $endpoint->created_by,
                    'assigned_to' => $endpoint->default_assigned_to ?? $endpoint->created_by,
                    'status' => 'active',
                ]),
                dealData: [
                    'property_id' => null,
                    'created_by' => $endpoint->created_by,
                    'assigned_to' => $endpoint->default_assigned_to ?? $endpoint->created_by,
                    'title' => $dealData['title'] ?? 'Webhook Lead',
                    'description' => $dealData['description'] ?? null,
                    'stage' => 'lead',
                    'lead_source' => $endpoint->leadSource?->slug,
                    'lead_source_id' => $endpoint->lead_source_id,
                    'external_id' => $dealData['external_id'] ?? null,
                    'sort_order' => Deal::where('stage', 'lead')->max('sort_order') + 1,
                ],
                options: [
                    'source_label' => $endpoint->leadSource?->name ?? 'Webhook',
                ],
            );

            $customer = $result->customer;
            $deal = $result->deal;

            // Link property from customer if deal doesn't have one
            if (! $deal->property_id && $customer->properties()->exists()) {
                $deal->update(['property_id' => $customer->properties()->first()->id]);
            }

            $log->update([
                'status' => 'processed',
                'customer_id' => $customer->id,
                'deal_id' => $deal->id,
                'processed_at' => now(),
            ]);

            $endpoint->increment('total_leads');
            $endpoint->update(['last_payload_at' => now()]);

            if (class_exists(\App\Events\NewLeadCreated::class)) {
                \App\Events\NewLeadCreated::dispatch($deal);
            }

            return $log;
        } catch (\Exception $e) {
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            report($e);

            return $log;
        }
    }

    protected function findOrCreateCustomer(array $data, WebhookEndpoint $endpoint): Customer
    {
        $email = $data['email'] ?? null;

        if ($email) {
            $customer = Customer::where('email', $email)->first();
            if ($customer) {
                return $customer;
            }
        }

        $phone = $data['phone'] ?? null;

        $customer = Customer::create([
            'first_name' => $data['first_name'] ?? 'Unknown',
            'last_name' => $data['last_name'] ?? '',
            'email' => $email,
            'phone' => $phone ? preg_replace('/[^0-9]/', '', $phone) : null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip' => $data['zip'] ?? null,
            'source' => $endpoint->leadSource?->slug,
            'lead_source_id' => $endpoint->lead_source_id,
            'created_by' => $endpoint->created_by,
            'assigned_to' => $endpoint->default_assigned_to ?? $endpoint->created_by,
            'status' => 'active',
        ]);

        app(\App\Services\CustomerDuplicateService::class)->checkAndFlagDuplicates($customer);

        return $customer;
    }

    protected function createDeal(Customer $customer, array $data, WebhookEndpoint $endpoint): Deal
    {
        return Deal::create([
            'customer_id' => $customer->id,
            'property_id' => $customer->properties()->first()?->id,
            'created_by' => $endpoint->created_by,
            'assigned_to' => $endpoint->default_assigned_to ?? $endpoint->created_by,
            'title' => $data['title'] ?? "{$customer->full_name} - Webhook Lead",
            'description' => $data['description'] ?? null,
            'stage' => 'lead',
            'lead_source' => $endpoint->leadSource?->slug,
            'lead_source_id' => $endpoint->lead_source_id,
            'external_id' => $data['external_id'] ?? null,
            'sort_order' => Deal::where('stage', 'lead')->max('sort_order') + 1,
        ]);
    }
}

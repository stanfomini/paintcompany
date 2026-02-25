<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\LeadSource;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Notifications\NewMessengerLead;
use Illuminate\Support\Facades\Log;

class MessengerLeadCreationService
{
    /**
     * Create Customer + Deal from a completed bot conversation.
     *
     * @return array{customer: Customer, deal: Deal, is_new_customer: bool}
     */
    public function createFromConversation(MessengerConversation $conversation): array
    {
        $mappedData = $conversation->getCollectedData();
        $customerData = $this->extractCustomerFields($mappedData);
        $dealData = $this->extractDealFields($mappedData);

        // Find or create customer
        $settings = MessengerSetting::getInstance();
        $isNewCustomer = false;
        $customer = $this->findExistingCustomer($customerData);

        if ($customer) {
            // Update existing customer with any new non-null fields
            $updates = array_filter($customerData, fn ($v) => $v !== null && $v !== '');
            if (! empty($updates)) {
                $customer->update($updates);
            }
        } else {
            $isNewCustomer = true;
            $leadSource = $this->getOrCreateLeadSource();

            $customer = Customer::create(array_merge([
                'first_name' => 'Unknown',
                'last_name' => '',
            ], $customerData, [
                'source' => 'facebook_messenger',
                'lead_source_id' => $leadSource->id,
                'status' => 'active',
                'created_by' => $settings->default_assigned_to ?? $this->getFirstInternalUserId(),
            ]));
        }

        // Create deal
        $isIncomplete = in_array($conversation->status, ['abandoned', 'human_takeover']);

        $firstName = $customerData['first_name'] ?? 'Unknown';
        $lastName = $customerData['last_name'] ?? '';
        $titleName = trim("{$firstName} {$lastName}") ?: 'Facebook Lead';
        $dealTitle = "{$titleName} - Facebook Lead".($isIncomplete ? ' (Incomplete)' : '');

        $leadSource = $this->getOrCreateLeadSource();

        $deal = Deal::create([
            'customer_id' => $customer->id,
            'title' => $dealData['title'] ?? $dealTitle,
            'description' => $dealData['description'] ?? null,
            'stage' => 'lead',
            'lead_source' => 'Facebook Messenger',
            'lead_source_id' => $leadSource->id,
            'assigned_to' => $settings->default_assigned_to,
            'created_by' => $settings->default_assigned_to,
        ]);

        // Link conversation to customer and deal
        $conversation->update([
            'customer_id' => $customer->id,
            'deal_id' => $deal->id,
        ]);

        // Link chat thread to customer
        if ($conversation->thread) {
            $conversation->thread->update(['customer_id' => $customer->id]);
        }

        // Notify assigned user
        $this->notifyAssignedUser($customer, $deal, $isIncomplete);

        return [
            'customer' => $customer,
            'deal' => $deal,
            'is_new_customer' => $isNewCustomer,
        ];
    }

    /**
     * Create a partial lead from an abandoned/timed-out conversation.
     *
     * @return array{customer: Customer, deal: Deal, is_new_customer: bool}
     */
    public function createPartialLead(MessengerConversation $conversation): array
    {
        return $this->createFromConversation($conversation);
    }

    /**
     * Extract customer fields from mapped data.
     *
     * @return array<string, mixed>
     */
    private function extractCustomerFields(array $mappedData): array
    {
        $fields = [];
        $customerPrefix = 'customer.';

        foreach ($mappedData as $key => $value) {
            if (str_starts_with($key, $customerPrefix)) {
                $field = substr($key, strlen($customerPrefix));
                $fields[$field] = $value;
            }
        }

        return $fields;
    }

    /**
     * Extract deal fields from mapped data.
     *
     * @return array<string, mixed>
     */
    private function extractDealFields(array $mappedData): array
    {
        $fields = [];
        $dealPrefix = 'deal.';

        foreach ($mappedData as $key => $value) {
            if (str_starts_with($key, $dealPrefix)) {
                $field = substr($key, strlen($dealPrefix));
                $fields[$field] = $value;
            }
        }

        return $fields;
    }

    /**
     * Find existing customer by email or phone match.
     */
    private function findExistingCustomer(array $customerData): ?Customer
    {
        // Try email match first
        if (! empty($customerData['email'])) {
            $customer = Customer::where('email', $customerData['email'])->first();
            if ($customer) {
                return $customer;
            }
        }

        // Try phone match (normalized)
        if (! empty($customerData['phone'])) {
            $normalizedPhone = $this->normalizePhone($customerData['phone']);
            if ($normalizedPhone) {
                $customer = Customer::get()->first(function ($c) use ($normalizedPhone) {
                    return $this->normalizePhone($c->phone ?? '') === $normalizedPhone;
                });
                if ($customer) {
                    return $customer;
                }
            }
        }

        return null;
    }

    /**
     * Normalize phone number by stripping non-digits.
     */
    public function normalizePhone(string $phone): string
    {
        return preg_replace('/\D/', '', $phone);
    }

    /**
     * Get the first internal user ID as a fallback for created_by.
     */
    private function getFirstInternalUserId(): ?int
    {
        return User::where('type', 'internal')->value('id');
    }

    /**
     * Get or create the "Facebook Messenger" lead source.
     */
    private function getOrCreateLeadSource(): LeadSource
    {
        return LeadSource::firstOrCreate(
            ['name' => 'Facebook Messenger'],
            ['is_active' => true, 'color' => '#0084ff']
        );
    }

    /**
     * Notify the assigned user about a new lead.
     */
    private function notifyAssignedUser(Customer $customer, Deal $deal, bool $isIncomplete): void
    {
        $settings = MessengerSetting::getInstance();

        if (! $settings->default_assigned_to) {
            return;
        }

        $user = User::find($settings->default_assigned_to);
        if (! $user || ! $user->isInternal()) {
            return;
        }

        try {
            $user->notify(new NewMessengerLead($customer, $deal, $isIncomplete));
        } catch (\Exception $e) {
            Log::warning('Failed to send new messenger lead notification', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}

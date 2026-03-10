<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\LeadSource;
use App\Models\MessengerConversation;
use App\Models\MessengerResponder;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Notifications\NewMessengerLead;
use Illuminate\Support\Facades\Log;

class MessengerLeadCreationService
{
    /**
     * Create Customer + Deal + draft Estimate from a completed bot conversation.
     *
     * @return array{customer: Customer, deal: Deal, estimate: Estimate|null, is_new_customer: bool}
     */
    public function createFromConversation(MessengerConversation $conversation): array
    {
        $mappedData = $conversation->getCollectedData();
        $customerData = $this->extractCustomerFields($mappedData);
        $dealData = $this->extractDealFields($mappedData);

        // Fall back to Facebook profile name if bot didn't collect a name
        $fbName = $conversation->getFbName();
        if (empty($customerData['first_name']) && ! empty($fbName['first_name'])) {
            $customerData['first_name'] = $fbName['first_name'];
        }
        if (empty($customerData['last_name']) && ! empty($fbName['last_name'])) {
            $customerData['last_name'] = $fbName['last_name'];
        }

        // Find or create customer
        $settings = MessengerSetting::getInstance();
        $assignmentService = app(MessengerLeadAssignmentService::class);
        $isNewCustomer = false;
        $customer = $this->findExistingCustomer($customerData, $conversation->facebook_psid);

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
                'created_by' => $assignmentService->getCreatedByUser()?->id ?? $this->getFirstInternalUserId(),
            ]));

            app(\App\Services\CustomerDuplicateService::class)->checkAndFlagDuplicates($customer);
        }

        // Check for existing active messenger deal for this customer
        $existingDeal = null;
        if (! $isNewCustomer) {
            $existingDeal = Deal::where('customer_id', $customer->id)
                ->where('lead_source', 'Facebook Messenger')
                ->whereNotIn('stage', ['lost', 'completed'])
                ->latest()
                ->first();
        }

        // Reuse existing deal or create new one
        $isIncomplete = in_array($conversation->status, ['abandoned', 'human_takeover']);
        $estimate = null;
        $assignedTo = null;

        if ($existingDeal) {
            $deal = $existingDeal;
            $estimate = $existingDeal->estimates()->latest()->first();
        } else {
            $firstName = $customerData['first_name'] ?? 'Unknown';
            $lastName = $customerData['last_name'] ?? '';
            $titleName = trim("{$firstName} {$lastName}") ?: 'Facebook Lead';
            $dealTitle = "{$titleName} - Facebook Lead".($isIncomplete ? ' (Incomplete)' : '');

            $leadSource = $this->getOrCreateLeadSource();

            // Determine deal assignment: if conversation was taken over by an estimator, assign directly
            if ($conversation->taken_over_by) {
                $takeoverRole = $assignmentService->getResponderRole(User::find($conversation->taken_over_by));
                if ($takeoverRole && in_array($takeoverRole, ['estimator', 'both'])) {
                    $assignedTo = $conversation->taken_over_by;
                }
            }

            $deal = Deal::create([
                'customer_id' => $customer->id,
                'title' => $dealData['title'] ?? $dealTitle,
                'description' => $dealData['description'] ?? null,
                'stage' => 'lead',
                'lead_source' => 'Facebook Messenger',
                'lead_source_id' => $leadSource->id,
                'assigned_to' => $assignedTo,
                'created_by' => $assignmentService->getCreatedByUser()?->id,
            ]);

            // Auto-create draft estimate
            $estimate = Estimate::create([
                'deal_id' => $deal->id,
                'created_by' => $assignmentService->getCreatedByUser()?->id,
                'title' => $dealData['title'] ?? $dealTitle,
                'status' => 'draft',
                'subtotal' => 0,
                'tax_amount' => 0,
                'total' => 0,
            ]);
        }

        // Link conversation to customer and deal
        $conversation->update([
            'customer_id' => $customer->id,
            'deal_id' => $deal->id,
        ]);

        // Link chat thread to customer
        if ($conversation->thread) {
            $conversation->thread->update(['customer_id' => $customer->id]);
        }

        // Only assign and notify for new deals
        if (! $existingDeal) {
            // Assign via round-robin if not already assigned by takeover
            if (! $assignedTo) {
                $assignmentService->assignViaRoundRobin($deal);
            }

            // Notify responders
            $this->notifyResponders($customer, $deal, $isIncomplete);
        }

        return [
            'customer' => $customer,
            'deal' => $deal,
            'estimate' => $estimate,
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
     * Create Customer + Deal + Estimate from manual conversion (hub button or booking form).
     *
     * @return array{customer: Customer, deal: Deal, estimate: Estimate, is_new_customer: bool}
     */
    public function createFromManualConversion(MessengerConversation $conversation, array $formData): array
    {
        $customerData = array_filter([
            'first_name' => $formData['first_name'] ?? null,
            'last_name' => $formData['last_name'] ?? null,
            'email' => $formData['email'] ?? null,
            'phone' => $formData['phone'] ?? null,
            'address' => $formData['address'] ?? null,
            'city' => $formData['city'] ?? null,
            'state' => $formData['state'] ?? null,
            'zip' => $formData['zip'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $assignmentService = app(MessengerLeadAssignmentService::class);
        $isNewCustomer = false;
        $customer = $this->findExistingCustomer($customerData, $conversation->facebook_psid);

        if ($customer) {
            $customer->update($customerData);
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
                'created_by' => $assignmentService->getCreatedByUser()?->id ?? $this->getFirstInternalUserId(),
            ]));

            app(\App\Services\CustomerDuplicateService::class)->checkAndFlagDuplicates($customer);
        }

        $firstName = $customerData['first_name'] ?? 'Unknown';
        $lastName = $customerData['last_name'] ?? '';
        $titleName = trim("{$firstName} {$lastName}") ?: 'Facebook Lead';
        $dealTitle = "{$titleName} - Facebook Lead";

        $leadSource = $this->getOrCreateLeadSource();

        $deal = Deal::create([
            'customer_id' => $customer->id,
            'title' => $dealTitle,
            'description' => $formData['notes'] ?? null,
            'stage' => 'lead',
            'lead_source' => 'Facebook Messenger',
            'lead_source_id' => $leadSource->id,
            'created_by' => $assignmentService->getCreatedByUser()?->id,
        ]);

        $estimate = Estimate::create([
            'deal_id' => $deal->id,
            'created_by' => $assignmentService->getCreatedByUser()?->id,
            'title' => $dealTitle,
            'status' => 'draft',
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
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

        // Assign via round-robin
        $assignmentService->assignViaRoundRobin($deal);

        // Notify
        $this->notifyResponders($customer, $deal, false);

        return [
            'customer' => $customer,
            'deal' => $deal,
            'estimate' => $estimate,
            'is_new_customer' => $isNewCustomer,
        ];
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
     * Find existing customer by PSID, email, or phone match.
     */
    private function findExistingCustomer(array $customerData, ?string $psid = null): ?Customer
    {
        // Try PSID match first — most reliable for messenger
        if ($psid) {
            $existingConversation = MessengerConversation::where('facebook_psid', $psid)
                ->whereNotNull('customer_id')
                ->latest()
                ->first();

            if ($existingConversation?->customer_id) {
                return Customer::find($existingConversation->customer_id);
            }
        }

        // Try email match
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
     * Notify all active responders about a new lead.
     * Falls back to default_assigned_to if no roster exists.
     */
    private function notifyResponders(Customer $customer, Deal $deal, bool $isIncomplete): void
    {
        $responders = MessengerResponder::query()->active()->with('user')->get();

        if ($responders->isNotEmpty()) {
            foreach ($responders as $responder) {
                if (! $responder->user || ! $responder->user->isInternal()) {
                    continue;
                }

                try {
                    $responder->user->notify(new NewMessengerLead($customer, $deal, $isIncomplete));
                } catch (\Exception $e) {
                    Log::warning('Failed to send new messenger lead notification', [
                        'user_id' => $responder->user_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return;
        }

        // Fallback: notify default_assigned_to
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

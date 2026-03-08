<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerDuplicateFlag;
use App\Models\User;
use Illuminate\Support\Collection;

class CustomerDuplicateService
{
    /**
     * Find potential duplicate customers and staff matching the given data.
     *
     * @param  array{email?: ?string, phone?: ?string, first_name?: ?string, last_name?: ?string}  $customerData
     * @return array{customers: Collection, staff: Collection}
     */
    public function findPotentialDuplicates(array $customerData, ?int $excludeCustomerId = null): array
    {
        $customerMatches = collect();
        $staffMatches = collect();

        $email = isset($customerData['email']) ? trim($customerData['email']) : null;
        $phone = isset($customerData['phone']) ? trim($customerData['phone']) : null;
        $firstName = isset($customerData['first_name']) ? trim($customerData['first_name']) : null;
        $lastName = isset($customerData['last_name']) ? trim($customerData['last_name']) : null;

        // Email matching - customers
        if (! empty($email)) {
            $emailCustomers = Customer::query()
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->when($excludeCustomerId, fn ($q) => $q->where('id', '!=', $excludeCustomerId))
                ->get();

            foreach ($emailCustomers as $customer) {
                $customerMatches->push(['customer' => $customer, 'match_type' => 'email']);
            }

            // Email matching - staff (agents and crew only)
            $emailStaff = User::query()
                ->whereIn('type', ['agent', 'crew'])
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->get();

            foreach ($emailStaff as $user) {
                $staffMatches->push(['user' => $user, 'match_type' => 'email']);
            }
        }

        // Phone matching - customers only
        if (! empty($phone)) {
            $normalizedPhone = preg_replace('/\D/', '', $phone);

            if (strlen($normalizedPhone) >= 7) {
                $phoneCandidates = Customer::query()
                    ->whereNotNull('phone')
                    ->where('phone', '!=', '')
                    ->when($excludeCustomerId, fn ($q) => $q->where('id', '!=', $excludeCustomerId))
                    ->get();

                foreach ($phoneCandidates as $candidate) {
                    $candidateNormalized = preg_replace('/\D/', '', $candidate->phone);

                    if (strlen($candidateNormalized) >= 7 && $candidateNormalized === $normalizedPhone) {
                        $customerMatches->push(['customer' => $candidate, 'match_type' => 'phone']);
                    }
                }
            }
        }

        // Name matching - customers only (both first AND last required)
        if (! empty($firstName) && ! empty($lastName)) {
            $nameCustomers = Customer::query()
                ->whereRaw('LOWER(first_name) = ?', [strtolower($firstName)])
                ->whereRaw('LOWER(last_name) = ?', [strtolower($lastName)])
                ->when($excludeCustomerId, fn ($q) => $q->where('id', '!=', $excludeCustomerId))
                ->get();

            foreach ($nameCustomers as $customer) {
                $customerMatches->push(['customer' => $customer, 'match_type' => 'name']);
            }
        }

        // Deduplicate customer matches - keep unique by customer ID, preserving first match_type
        $customerMatches = $customerMatches->unique(fn ($match) => $match['customer']->id)->values();
        $staffMatches = $staffMatches->unique(fn ($match) => $match['user']->id)->values();

        return [
            'customers' => $customerMatches,
            'staff' => $staffMatches,
        ];
    }

    /**
     * Check a customer against existing records and create duplicate flags for each match.
     */
    public function checkAndFlagDuplicates(Customer $customer): void
    {
        $results = $this->findPotentialDuplicates([
            'email' => $customer->email,
            'phone' => $customer->phone,
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
        ], $customer->id);

        foreach ($results['customers'] as $match) {
            $this->createFlagIfNotDismissed(
                customerId: $customer->id,
                matchType: $match['match_type'],
                duplicateCustomerId: $match['customer']->id,
                duplicateUserId: null,
            );
        }

        foreach ($results['staff'] as $match) {
            $this->createFlagIfNotDismissed(
                customerId: $customer->id,
                matchType: $match['match_type'],
                duplicateCustomerId: null,
                duplicateUserId: $match['user']->id,
            );
        }
    }

    /**
     * Dismiss a duplicate flag.
     */
    public function dismiss(CustomerDuplicateFlag $flag, User $resolvedBy): void
    {
        $flag->update([
            'status' => 'dismissed',
            'resolved_by' => $resolvedBy->id,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Create a flag only if no flag (including dismissed) already exists for this exact pair and match type.
     */
    private function createFlagIfNotDismissed(int $customerId, string $matchType, ?int $duplicateCustomerId, ?int $duplicateUserId): void
    {
        $exists = CustomerDuplicateFlag::query()
            ->where('customer_id', $customerId)
            ->where('match_type', $matchType)
            ->when($duplicateCustomerId, fn ($q) => $q->where('duplicate_customer_id', $duplicateCustomerId))
            ->when($duplicateUserId, fn ($q) => $q->where('duplicate_user_id', $duplicateUserId))
            ->when(! $duplicateCustomerId, fn ($q) => $q->whereNull('duplicate_customer_id'))
            ->when(! $duplicateUserId, fn ($q) => $q->whereNull('duplicate_user_id'))
            ->exists();

        if ($exists) {
            return;
        }

        CustomerDuplicateFlag::create([
            'customer_id' => $customerId,
            'duplicate_customer_id' => $duplicateCustomerId,
            'duplicate_user_id' => $duplicateUserId,
            'match_type' => $matchType,
            'status' => 'pending',
        ]);
    }
}

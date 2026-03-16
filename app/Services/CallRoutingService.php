<?php

namespace App\Services;

use App\Models\CallGroup;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Carbon;

class CallRoutingService
{
    public function __construct(
        public TwilioService $twilio
    ) {}

    /**
     * Resolve which call group should handle an inbound call.
     */
    public function resolveCallGroup(string $fromNumber): ?CallGroup
    {
        $settings = CompanySetting::getInstance();

        // Try smart routing: look up customer → assigned estimator → their call group
        $identity = $this->findCallerIdentity($fromNumber);

        if ($identity['customer']) {
            $assignedUser = $this->resolveAssignedContact($identity['customer']);

            if ($assignedUser) {
                $memberGroup = $assignedUser->callGroupMemberships()
                    ->where('is_active', true)
                    ->first()
                    ?->callGroup;

                if ($memberGroup && $memberGroup->is_active) {
                    return $memberGroup;
                }
            }
        }

        // Check business hours → after hours group
        if (! $this->isBusinessHours() && $settings->after_hours_call_group_id) {
            $afterHoursGroup = $settings->afterHoursCallGroup;

            if ($afterHoursGroup && $afterHoursGroup->is_active) {
                return $afterHoursGroup;
            }
        }

        // Fall back to default call group
        if ($settings->default_call_group_id) {
            return $settings->defaultCallGroup;
        }

        return null;
    }

    /**
     * Check if the current time is within business hours.
     */
    public function isBusinessHours(): bool
    {
        $settings = CompanySetting::getInstance();

        $start = $settings->phone_business_hours_start;
        $end = $settings->phone_business_hours_end;

        if (! $start || ! $end) {
            return true; // No business hours configured = always open
        }

        $timezone = $settings->timezone ?? 'America/Denver';
        $now = Carbon::now($timezone);

        $businessDays = $settings->phone_business_days ?? [1, 2, 3, 4, 5]; // Mon-Fri default
        $currentDay = $now->dayOfWeekIso; // 1 = Monday, 7 = Sunday

        if (! in_array($currentDay, $businessDays)) {
            return false;
        }

        $startTime = Carbon::parse($start, $timezone);
        $endTime = Carbon::parse($end, $timezone);

        return $now->between($startTime, $endTime);
    }

    /**
     * Find caller identity from phone number.
     *
     * @return array{user: ?User, customer: ?Customer}
     */
    public function findCallerIdentity(string $phone): array
    {
        $formatted = $this->twilio->formatE164($phone);

        if (! $formatted) {
            return ['user' => null, 'customer' => null];
        }

        $digits = preg_replace('/\D/', '', $formatted);
        $last10 = substr($digits, -10);
        $phoneLike = '%'.$last10;

        // Find user by phone
        $user = User::where('phone', 'LIKE', $phoneLike)->first();

        // Find customer by phone
        $customer = Customer::where('phone', 'LIKE', $phoneLike)->first();

        // If we found a user but no customer, check user's CRM customer record
        if ($user && ! $customer) {
            $customer = $user->customer;
        }

        return ['user' => $user, 'customer' => $customer];
    }

    /**
     * Resolve a user by their extension number.
     */
    public function resolveFromExtension(string $digits): ?User
    {
        return User::where('extension', $digits)
            ->whereNotNull('forwarding_number')
            ->where('type', 'internal')
            ->first();
    }

    /**
     * Resolve which call group an IVR digit maps to.
     */
    public function resolveIvrOption(string $digit, array $ivrMenu): ?CallGroup
    {
        $options = $ivrMenu['options'] ?? [];

        foreach ($options as $option) {
            if (($option['digit'] ?? '') === $digit) {
                return CallGroup::find($option['call_group_id'] ?? null);
            }
        }

        return null;
    }

    /**
     * Resolve the assigned internal contact for a customer.
     */
    public function resolveAssignedContact(Customer $customer): ?User
    {
        // Check directly assigned estimator/salesperson
        if ($customer->assigned_to) {
            $assignedUser = $customer->assignedTo;

            if ($assignedUser && $assignedUser->isActive() && $assignedUser->isInternal()) {
                return $assignedUser;
            }
        }

        // Check active deal's assigned user
        $activeDeal = $customer->deals()
            ->active()
            ->whereNotNull('assigned_to')
            ->latest()
            ->first();

        if ($activeDeal && $activeDeal->assigned_to) {
            $dealUser = User::find($activeDeal->assigned_to);

            if ($dealUser && $dealUser->isActive() && $dealUser->isInternal()) {
                return $dealUser;
            }
        }

        return null;
    }
}

<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\CommissionEntry;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\EmployeeProfile;
use App\Models\Estimate;
use App\Models\GuestChatSession;
use App\Models\Invoice;
use App\Models\Property;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\ReferralEstimateSigned;
use App\Notifications\ReferralLeadCreated;
use App\Notifications\ReferralRewardEarned;

class ReferralService
{
    /**
     * Resolve a referrer from a referral code.
     */
    public function resolveReferrer(string $code): ?User
    {
        return User::where('referral_code', $code)->first();
    }

    /**
     * Create a referral from a lead form submission.
     * Creates Customer + Deal + Referral if referral code is present.
     *
     * @param  array<string, mixed>  $data
     * @return array{customer: Customer, deal: Deal, referral: Referral|null}
     */
    public function createFromLeadForm(array $data, ?string $referralCode = null): array
    {
        $settings = CompanySetting::getInstance();

        // Public leads have no authenticated user - attribute to first internal user
        $createdBy = User::where('type', 'internal')->first()?->id;

        $customer = Customer::create([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip' => $data['zip'] ?? null,
            'source' => $referralCode ? 'referral' : 'online',
            'estimate_preference' => $data['estimate_preference'] ?? null,
            'status' => 'active',
            'created_by' => $createdBy,
        ]);

        // Find auto-created property (from Customer model saved hook) or create if needed
        $property = null;
        if (! empty($data['address']) && ! empty($data['city'])) {
            $property = $customer->properties()
                ->where('address', $data['address'])
                ->where('city', $data['city'])
                ->first();
        }

        $deal = Deal::create([
            'customer_id' => $customer->id,
            'property_id' => $property?->id,
            'title' => "Painting Estimate - {$customer->full_name}",
            'description' => $data['description'] ?? null,
            'stage' => 'lead',
            'lead_source' => $referralCode ? 'referral' : 'website',
            'created_by' => $createdBy,
        ]);

        $referral = null;

        if ($referralCode && $settings->referral_program_enabled) {
            $referrer = $this->resolveReferrer($referralCode);
            if ($referrer) {
                $referral = Referral::create([
                    'referrer_id' => $referrer->id,
                    'customer_id' => $customer->id,
                    'deal_id' => $deal->id,
                    'status' => Referral::STATUS_LEAD,
                    'reward_amount' => $settings->referral_reward_amount,
                ]);

                $referrer->notify(new ReferralLeadCreated($referral));
            }
        }

        // Link any active guest chat sessions with matching email
        GuestChatSession::where('email', $data['email'])
            ->where('status', 'active')
            ->each(function (GuestChatSession $session) use ($customer) {
                $session->update([
                    'status' => 'converted',
                    'user_id' => $customer->user_id ?? null,
                ]);
            });

        return [
            'customer' => $customer,
            'deal' => $deal,
            'referral' => $referral,
        ];
    }

    /**
     * Called when an estimate is sent (status -> 'sent').
     * Advances referral from 'lead' to 'quoted'.
     */
    public function onEstimateSent(Estimate $estimate): void
    {
        $referral = $this->findReferralForEstimate($estimate);
        if ($referral && $referral->status === Referral::STATUS_LEAD) {
            $referral->advanceTo(Referral::STATUS_QUOTED);
        }
    }

    /**
     * Called when an estimate is signed by customer.
     * Advances referral to 'signed' and notifies referrer.
     */
    public function onEstimateSigned(Estimate $estimate): void
    {
        $referral = $this->findReferralForEstimate($estimate);
        if (! $referral) {
            return;
        }

        if ($referral->advanceTo(Referral::STATUS_SIGNED)) {
            $referral->referrer->notify(new ReferralEstimateSigned($referral));
        }
    }

    /**
     * Called when a deposit invoice is paid.
     * Advances referral to 'deposit_paid', notifies referrer, creates admin task.
     */
    public function onDepositPaid(Invoice $invoice): void
    {
        $job = $invoice->job;
        if (! $job) {
            return;
        }

        $estimate = $job->estimate;
        if (! $estimate) {
            return;
        }

        $referral = $this->findReferralForEstimate($estimate);
        if (! $referral) {
            return;
        }

        if ($referral->advanceTo(Referral::STATUS_DEPOSIT_PAID)) {
            $referral->referrer->notify(new ReferralRewardEarned($referral));

            $this->createRewardTask($referral);
            $this->createReferralCommissionEntry($referral);
        }
    }

    /**
     * Admin marks the referral reward as sent.
     */
    public function markRewardSent(Referral $referral, ?string $notes = null): bool
    {
        $referral->reward_sent_at = now();
        $referral->reward_notes = $notes;

        return $referral->advanceTo(Referral::STATUS_REWARD_SENT);
    }

    /**
     * Get referral stats for a specific user.
     *
     * @return array<string, mixed>
     */
    public function getStatsForUser(User $user): array
    {
        $referrals = $user->referrals;

        return [
            'total' => $referrals->count(),
            'leads' => $referrals->where('status', Referral::STATUS_LEAD)->count(),
            'quoted' => $referrals->where('status', Referral::STATUS_QUOTED)->count(),
            'signed' => $referrals->where('status', Referral::STATUS_SIGNED)->count(),
            'deposit_paid' => $referrals->where('status', Referral::STATUS_DEPOSIT_PAID)->count(),
            'reward_sent' => $referrals->where('status', Referral::STATUS_REWARD_SENT)->count(),
            'total_earned' => $referrals->whereIn('status', [Referral::STATUS_DEPOSIT_PAID, Referral::STATUS_REWARD_SENT])->sum('reward_amount'),
            'pending_rewards' => $referrals->where('status', Referral::STATUS_DEPOSIT_PAID)->sum('reward_amount'),
        ];
    }

    /**
     * Find the referral record for an estimate (through deal).
     */
    protected function findReferralForEstimate(Estimate $estimate): ?Referral
    {
        if (! $estimate->deal_id) {
            return null;
        }

        return Referral::where('deal_id', $estimate->deal_id)->first();
    }

    /**
     * Create an admin task to send the referral reward.
     */
    protected function createRewardTask(Referral $referral): void
    {
        $referral->load(['referrer', 'customer']);

        $customerName = $referral->customer->full_name ?? 'Customer';
        $referrerName = $referral->referrer->name ?? 'Referrer';
        $amount = number_format($referral->reward_amount, 2);

        $paymentMethod = $referral->referrer->referral_payment_method ?? 'unknown';
        $paymentHandle = $referral->referrer->referral_payment_handle ?? 'N/A';

        $firstInternal = User::where('type', 'internal')->first();

        Activity::create([
            'user_id' => $firstInternal?->id,
            'assigned_to' => $firstInternal?->id,
            'subject_type' => Customer::class,
            'subject_id' => $referral->customer_id,
            'type' => 'task',
            'title' => "Send \${$amount} referral reward to {$referrerName} via {$paymentMethod} {$paymentHandle}",
            'description' => "{$referrerName} earned a referral reward for referring {$customerName}. Payment: {$paymentMethod} {$paymentHandle}",
            'is_completed' => false,
            'due_at' => now()->addDays(3),
        ]);
    }

    /**
     * Create a commission entry to track the referral reward in the unified commission system.
     */
    protected function createReferralCommissionEntry(Referral $referral): ?CommissionEntry
    {
        $exists = CommissionEntry::query()
            ->where('commissionable_type', User::class)
            ->where('commissionable_id', $referral->referrer_id)
            ->where('source_type', Referral::class)
            ->where('source_id', $referral->id)
            ->whereNotIn('status', ['reversed'])
            ->exists();

        if ($exists) {
            return null;
        }

        $referral->load(['referrer', 'customer']);
        $customerName = $referral->customer->full_name ?? 'Customer';

        $employeeProfileId = EmployeeProfile::where('user_id', $referral->referrer_id)
            ->where('employment_status', 'active')
            ->value('id');

        return CommissionEntry::create([
            'commissionable_type' => User::class,
            'commissionable_id' => $referral->referrer_id,
            'employee_profile_id' => $employeeProfileId,
            'source_type' => Referral::class,
            'source_id' => $referral->id,
            'source_label' => "Referral: {$customerName}",
            'commission_type' => 'flat',
            'basis_amount' => 0,
            'rate' => 0,
            'amount' => (float) $referral->reward_amount,
            'status' => 'pending',
            'earned_date' => now()->toDateString(),
        ]);
    }
}

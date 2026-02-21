<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Models\CommissionEntry;
use App\Models\EmployeeProfile;
use App\Models\LeadSource;
use App\Models\Vendor;
use App\Models\WorkflowRun;

class CreateLeadSourceCommission implements ActionInterface
{
    public static function getName(): string
    {
        return 'Create Lead Source Commission';
    }

    public static function getCategory(): string
    {
        return 'Financial';
    }

    public static function getDescription(): string
    {
        return 'Creates a commission entry for the vendor associated with the lead source. Used to track referral fees and marketing commissions.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'commission_type' => [
                'type' => 'string',
                'label' => 'Commission Type',
                'description' => 'How to calculate the commission.',
                'required' => true,
                'default' => 'flat',
                'options' => [
                    'flat' => 'Flat Amount',
                    'revenue_percentage' => 'Percentage of Revenue',
                    'profit_percentage' => 'Percentage of Profit',
                ],
            ],
            'amount' => [
                'type' => 'number',
                'label' => 'Flat Amount ($)',
                'description' => 'Fixed commission amount (used when type is "flat").',
                'required' => false,
                'default' => 0,
            ],
            'rate' => [
                'type' => 'number',
                'label' => 'Rate (%)',
                'description' => 'Commission percentage (e.g., 10 for 10%). Used for revenue/profit percentage types.',
                'required' => false,
                'default' => 0,
            ],
            'lead_source_slug' => [
                'type' => 'string',
                'label' => 'Lead Source',
                'description' => 'The lead source slug. Leave empty to use the source from the workflow context.',
                'required' => false,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function execute(WorkflowRun $run, array $config): array
    {
        $leadSource = $this->resolveLeadSource($run, $config);

        if (! $leadSource || ! $leadSource->vendor_id) {
            return ['commission_created' => false, 'reason' => 'No vendor linked to lead source'];
        }

        $vendor = $leadSource->vendor;
        if (! $vendor) {
            return ['commission_created' => false, 'reason' => 'Vendor not found'];
        }

        // Optional: find employee profile for backwards compat (vendor may or may not have one)
        $employeeProfile = $this->findEmployeeProfileForVendor($vendor);

        $commissionAmount = $this->calculateCommission($run, $config);
        if ($commissionAmount <= 0) {
            return ['commission_created' => false, 'reason' => 'Commission amount is zero'];
        }

        $commissionType = $config['commission_type'] ?? 'flat';
        $rate = (float) ($config['rate'] ?? 0) / 100;
        $revenue = (float) ($run->context['estimate_total'] ?? $run->context['deal_value'] ?? 0);
        $profit = (float) ($run->context['estimate_profit'] ?? 0);
        $sourceModel = $run->subject;

        $entry = CommissionEntry::create([
            'commissionable_type' => Vendor::class,
            'commissionable_id' => $vendor->id,
            'employee_profile_id' => $employeeProfile?->id,
            'source_type' => $sourceModel ? get_class($sourceModel) : null,
            'source_id' => $sourceModel?->getKey(),
            'source_label' => "Lead Source Commission: {$leadSource->name}",
            'commission_type' => $commissionType,
            'basis_amount' => match ($commissionType) {
                'revenue_percentage' => $revenue,
                'profit_percentage' => $profit,
                default => 0,
            },
            'rate' => $rate,
            'amount' => $commissionAmount,
            'status' => 'pending',
            'earned_date' => now()->toDateString(),
            'notes' => "Auto-created by workflow. Vendor: {$vendor->name}",
        ]);

        $context = $run->context ?? [];
        $context['commission_entry_id'] = $entry->id;
        $context['commission_amount'] = $commissionAmount;
        $run->update(['context' => $context]);

        return [
            'commission_created' => true,
            'commission_entry_id' => $entry->id,
            'amount' => $commissionAmount,
            'vendor_name' => $vendor->name,
        ];
    }

    protected function resolveLeadSource(WorkflowRun $run, array $config): ?LeadSource
    {
        $slug = $config['lead_source_slug'] ?? null;

        if ($slug) {
            return LeadSource::findBySlug($slug);
        }

        $leadSourceId = $run->context['lead_source_id'] ?? null;

        if ($leadSourceId) {
            return LeadSource::find($leadSourceId);
        }

        return null;
    }

    protected function calculateCommission(WorkflowRun $run, array $config): float
    {
        $commissionType = $config['commission_type'] ?? 'flat';
        $amount = (float) ($config['amount'] ?? 0);
        $rate = (float) ($config['rate'] ?? 0) / 100;

        $revenue = (float) ($run->context['estimate_total'] ?? $run->context['deal_value'] ?? 0);
        $profit = (float) ($run->context['estimate_profit'] ?? 0);

        return match ($commissionType) {
            'revenue_percentage' => round($revenue * $rate, 2),
            'profit_percentage' => round($profit * $rate, 2),
            'flat' => round($amount, 2),
            default => 0,
        };
    }

    /**
     * Find an active employee profile for a vendor by matching the vendor's email to a user account.
     */
    protected function findEmployeeProfileForVendor(Vendor $vendor): ?EmployeeProfile
    {
        if (! $vendor->email) {
            return null;
        }

        $user = \App\Models\User::where('email', $vendor->email)->first();

        if (! $user) {
            return null;
        }

        return EmployeeProfile::where('user_id', $user->id)
            ->where('employment_status', 'active')
            ->first();
    }
}

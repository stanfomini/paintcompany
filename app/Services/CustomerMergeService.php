<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\CustomerDuplicateFlag;
use App\Models\Deal;
use App\Models\MessengerConversation;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CustomerMergeService
{
    /**
     * Mergeable fields that can be selected from either customer.
     *
     * @var array<int, string>
     */
    private const MERGEABLE_FIELDS = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'zip',
        'notes',
        'source',
        'lead_source_id',
    ];

    /**
     * Merge the absorbed customer into the primary customer.
     */
    public function merge(Customer $primary, Customer $absorbed, array $fieldSelections, User $resolvedBy): Customer
    {
        return DB::transaction(function () use ($primary, $absorbed, $fieldSelections, $resolvedBy) {
            $this->applyFieldSelections($primary, $fieldSelections);

            $this->reassignRelatedRecords($primary, $absorbed);

            $this->transferPortalUser($primary, $absorbed);

            $this->markDuplicateFlagsAsMerged($primary, $absorbed, $resolvedBy);

            $this->cascadePendingFlags($primary, $absorbed);

            $this->logMergeActivity($primary, $absorbed, $resolvedBy);

            $absorbed->delete();

            return $primary->fresh();
        });
    }

    /**
     * Get a summary of records that will be reassigned during merge.
     *
     * @return array{deals: int, properties: int, conversations: int, activities: int}
     */
    public function getMergeSummary(Customer $primary, Customer $absorbed): array
    {
        return [
            'deals' => Deal::where('customer_id', $absorbed->id)->count(),
            'properties' => Property::where('customer_id', $absorbed->id)->count(),
            'conversations' => MessengerConversation::where('customer_id', $absorbed->id)->count(),
            'activities' => Activity::where('subject_type', Customer::class)
                ->where('subject_id', $absorbed->id)
                ->count(),
        ];
    }

    /**
     * Apply selected field values to the primary customer.
     */
    private function applyFieldSelections(Customer $primary, array $fieldSelections): void
    {
        $updates = [];

        foreach ($fieldSelections as $field => $value) {
            if (in_array($field, self::MERGEABLE_FIELDS, true)) {
                $updates[$field] = $value;
            }
        }

        if ($updates) {
            $primary->update($updates);
        }
    }

    /**
     * Reassign all related records from absorbed to primary customer.
     */
    private function reassignRelatedRecords(Customer $primary, Customer $absorbed): void
    {
        Deal::where('customer_id', $absorbed->id)
            ->update(['customer_id' => $primary->id]);

        Property::where('customer_id', $absorbed->id)
            ->update(['customer_id' => $primary->id]);

        MessengerConversation::where('customer_id', $absorbed->id)
            ->update(['customer_id' => $primary->id]);

        ChatThread::where('customer_id', $absorbed->id)
            ->update(['customer_id' => $primary->id]);

        Activity::where('subject_type', Customer::class)
            ->where('subject_id', $absorbed->id)
            ->update(['subject_id' => $primary->id]);
    }

    /**
     * Transfer portal user access if primary lacks it but absorbed has it.
     */
    private function transferPortalUser(Customer $primary, Customer $absorbed): void
    {
        if ($primary->user_id === null && $absorbed->user_id !== null) {
            $primary->update(['user_id' => $absorbed->user_id]);
        }
    }

    /**
     * Mark duplicate flags between primary and absorbed as merged.
     */
    private function markDuplicateFlagsAsMerged(Customer $primary, Customer $absorbed, User $resolvedBy): void
    {
        CustomerDuplicateFlag::where(function ($query) use ($primary, $absorbed) {
            $query->where('customer_id', $primary->id)
                ->where('duplicate_customer_id', $absorbed->id);
        })->orWhere(function ($query) use ($primary, $absorbed) {
            $query->where('customer_id', $absorbed->id)
                ->where('duplicate_customer_id', $primary->id);
        })->update([
            'status' => 'merged',
            'resolved_by' => $resolvedBy->id,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Cascade any remaining pending flags from absorbed to primary.
     */
    private function cascadePendingFlags(Customer $primary, Customer $absorbed): void
    {
        CustomerDuplicateFlag::where('customer_id', $absorbed->id)
            ->where('status', 'pending')
            ->update(['customer_id' => $primary->id]);
    }

    /**
     * Log an activity entry for the merge.
     */
    private function logMergeActivity(Customer $primary, Customer $absorbed, User $resolvedBy): void
    {
        Activity::create([
            'subject_type' => Customer::class,
            'subject_id' => $primary->id,
            'user_id' => $resolvedBy->id,
            'type' => 'customer_merged',
            'title' => 'Customer Merged',
            'description' => "Merged customer {$absorbed->full_name} (ID: {$absorbed->id}) into this record.",
        ]);
    }
}

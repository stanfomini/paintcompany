<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\EmailCrmLink;
use App\Models\Job;
use App\Models\VideoCall;
use App\Models\VideoRecording;
use Illuminate\Support\Facades\DB;

class CustomerDeletionService
{
    /**
     * Get a summary of related records that will be deleted.
     *
     * @return array{deals: int, properties: int, estimates: int, jobs: int}
     */
    public function getDeletionSummary(Customer $customer): array
    {
        $dealIds = $customer->deals()->withTrashed()->pluck('id');
        $jobCount = Job::whereIn('deal_id', $dealIds)->withTrashed()->count();
        $estimateCount = $customer->estimates()->count();

        return [
            'deals' => $dealIds->count(),
            'properties' => $customer->properties()->count(),
            'estimates' => $estimateCount,
            'jobs' => $jobCount,
        ];
    }

    /**
     * Permanently delete a customer and all related data.
     */
    public function forceDelete(Customer $customer): void
    {
        DB::transaction(function () use ($customer) {
            $dealIds = $customer->deals()->withTrashed()->pluck('id')->all();
            $jobIds = Job::whereIn('deal_id', $dealIds)->withTrashed()->pluck('id')->all();

            $this->deletePolymorphicRecords($customer, $dealIds, $jobIds);

            $customer->forceDelete();
        });
    }

    /**
     * Delete polymorphic records that have no FK cascade constraints.
     *
     * @param  array<int>  $dealIds
     * @param  array<int>  $jobIds
     */
    private function deletePolymorphicRecords(Customer $customer, array $dealIds, array $jobIds): void
    {
        // Activities for customer, deals, and jobs
        $subjectMap = [
            Customer::class => [$customer->id],
            Deal::class => $dealIds,
            Job::class => $jobIds,
        ];

        foreach ($subjectMap as $type => $ids) {
            if (! empty($ids)) {
                Activity::where('subject_type', $type)
                    ->whereIn('subject_id', $ids)
                    ->delete();
            }
        }

        // EmailCrmLinks for customer and deals
        EmailCrmLink::where('linkable_type', Customer::class)
            ->where('linkable_id', $customer->id)
            ->delete();

        if (! empty($dealIds)) {
            EmailCrmLink::where('linkable_type', Deal::class)
                ->whereIn('linkable_id', $dealIds)
                ->delete();

            VideoCall::where('callable_type', Deal::class)
                ->whereIn('callable_id', $dealIds)
                ->delete();
        }

        if (! empty($jobIds)) {
            VideoRecording::where('recordable_type', Job::class)
                ->whereIn('recordable_id', $jobIds)
                ->delete();
        }
    }
}

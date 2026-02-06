<?php

namespace App\Services;

use App\Models\Job;
use App\Models\JobActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class JobDispatchService
{
    /**
     * Get crews eligible for a job (crew_size >= required_crew_size).
     *
     * @return Collection<int, User>
     */
    public function getEligibleCrews(Job $job): Collection
    {
        return User::query()
            ->where('type', 'crew')
            ->where('is_active', true)
            ->whereHas('crewProfile', function ($q) use ($job) {
                $q->where('crew_size', '>=', $job->required_crew_size);
            })
            ->with('crewProfile')
            ->orderBy('name')
            ->get();
    }

    /**
     * Dispatch a job to multiple crews.
     *
     * @param  array<int>  $crewUserIds
     */
    public function dispatchToCrews(Job $job, array $crewUserIds): int
    {
        $dispatched = 0;

        foreach ($crewUserIds as $crewUserId) {
            if (! $job->crews()->where('crew_user_id', $crewUserId)->exists()) {
                $job->crews()->attach($crewUserId, [
                    'status' => 'dispatched',
                    'dispatched_at' => now(),
                ]);
                $dispatched++;
            }
        }

        if ($dispatched > 0 && ! $job->dispatched_at) {
            $job->update(['dispatched_at' => now()]);
        }

        if ($dispatched > 0) {
            JobActivity::log($job, 'crew_dispatched', "Job dispatched to {$dispatched} crew(s)", [
                'crew_ids' => $crewUserIds,
                'dispatched_count' => $dispatched,
            ]);
        }

        return $dispatched;
    }

    /**
     * Accept a dispatched job.
     */
    public function acceptJob(Job $job, User $crew, ?string $notes = null): bool
    {
        $pivot = $job->crews()->where('crew_user_id', $crew->id)->first();

        if (! $pivot || $pivot->pivot->status !== 'dispatched') {
            return false;
        }

        $job->crews()->updateExistingPivot($crew->id, [
            'status' => 'accepted',
            'responded_at' => now(),
            'notes' => $notes,
        ]);

        JobActivity::log($job, 'crew_accepted', "{$crew->name} accepted the job", [
            'crew_user_id' => $crew->id,
            'crew_name' => $crew->name,
        ]);

        return true;
    }

    /**
     * Reject a dispatched job.
     */
    public function rejectJob(Job $job, User $crew, ?string $notes = null): bool
    {
        $pivot = $job->crews()->where('crew_user_id', $crew->id)->first();

        if (! $pivot || $pivot->pivot->status !== 'dispatched') {
            return false;
        }

        $job->crews()->updateExistingPivot($crew->id, [
            'status' => 'rejected',
            'responded_at' => now(),
            'notes' => $notes,
        ]);

        JobActivity::log($job, 'crew_rejected', "{$crew->name} rejected the job", [
            'crew_user_id' => $crew->id,
            'crew_name' => $crew->name,
            'notes' => $notes,
        ]);

        return true;
    }

    /**
     * Get jobs dispatched to a crew that are still awaiting response.
     *
     * @return Collection<int, Job>
     */
    public function getAvailableJobsForCrew(User $crew): Collection
    {
        return Job::query()
            ->whereHas('crews', function ($q) use ($crew) {
                $q->where('crew_user_id', $crew->id)
                    ->where('status', 'dispatched');
            })
            ->with(['deal.customer', 'estimate', 'colors'])
            ->active()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get jobs accepted by a crew.
     *
     * @return Collection<int, Job>
     */
    public function getAcceptedJobsForCrew(User $crew): Collection
    {
        return Job::query()
            ->whereHas('crews', function ($q) use ($crew) {
                $q->where('crew_user_id', $crew->id)
                    ->where('status', 'accepted');
            })
            ->with(['deal.customer', 'estimate', 'colors'])
            ->orderBy('scheduled_date')
            ->get();
    }
}

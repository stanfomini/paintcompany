<?php

namespace App\Services;

use App\Events\CrewDispatched;
use App\Events\JobAcceptedByCrew;
use App\Events\JobDispatchedToCrew;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\JobServiceRequirement;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Notifications\CrewJobDispatched;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class JobDispatchService
{
    private PlannerAutomationService $plannerAutomation;

    public function __construct(?PlannerAutomationService $plannerAutomation = null)
    {
        $this->plannerAutomation = $plannerAutomation ?? app(PlannerAutomationService::class);
    }

    /**
     * Get crews eligible for a job, optionally filtered by service category.
     *
     * When a category is provided, filters by crewProfile.service_category_id
     * and checks crew_size against the JobServiceRequirement's required_crew_size.
     *
     * @return Collection<int, User>
     */
    public function getEligibleCrews(Job $job, ?ServiceCategory $category = null): Collection
    {
        return User::query()
            ->where('type', 'crew')
            ->where('is_active', true)
            ->whereHas('crewProfile', function ($q) use ($job, $category) {
                if ($category) {
                    $q->where('service_category_id', $category->id);

                    $requirement = $job->serviceRequirements()
                        ->where('service_category_id', $category->id)
                        ->first();

                    $requiredSize = $requirement?->required_crew_size ?? $job->required_crew_size;
                    $q->where('crew_size', '>=', $requiredSize);
                } else {
                    $q->where('crew_size', '>=', $job->required_crew_size);
                }
            })
            ->with('crewProfile')
            ->orderBy('name')
            ->get();
    }

    /**
     * Dispatch a job to multiple crews, optionally tagged with a service category.
     *
     * @param  array<int>  $crewUserIds
     */
    public function dispatchToCrews(Job $job, array $crewUserIds, ?ServiceCategory $category = null): int
    {
        $dispatched = 0;

        foreach ($crewUserIds as $crewUserId) {
            $exists = $job->crews()
                ->where('crew_user_id', $crewUserId)
                ->when($category, fn ($q) => $q->where('job_crew.service_category_id', $category->id))
                ->exists();

            if (! $exists) {
                $job->crews()->attach($crewUserId, [
                    'status' => 'dispatched',
                    'dispatched_at' => now(),
                    'service_category_id' => $category?->id,
                ]);
                $dispatched++;
            }
        }

        if ($dispatched > 0 && ! $job->dispatched_at) {
            $job->update(['dispatched_at' => now()]);
        }

        if ($dispatched > 0) {
            // Update the service requirement status
            if ($category) {
                $job->serviceRequirements()
                    ->where('service_category_id', $category->id)
                    ->where('status', JobServiceRequirement::STATUS_PENDING)
                    ->update(['status' => JobServiceRequirement::STATUS_DISPATCHED]);
            }

            $categoryLabel = $category ? " for {$category->name}" : '';

            JobActivity::log($job, 'crew_dispatched', "Job dispatched to {$dispatched} crew(s){$categoryLabel}", [
                'crew_ids' => $crewUserIds,
                'dispatched_count' => $dispatched,
                'service_category_id' => $category?->id,
            ]);

            CrewDispatched::dispatch($job, $dispatched, $category?->id);

            // Notify each crew member on their personal channel + email
            foreach ($crewUserIds as $crewUserId) {
                JobDispatchedToCrew::dispatch($job, $crewUserId, $category?->id);

                $crewUser = User::find($crewUserId);
                $crewUser?->notify(new CrewJobDispatched($job, $category));
            }

            $this->plannerAutomation->onCrewDispatched($job, $dispatched);
        }

        return $dispatched;
    }

    /**
     * Accept a dispatched job with race condition protection.
     *
     * Uses a DB transaction with row locking to prevent multiple crews
     * from accepting the same job simultaneously. When a crew accepts,
     * all other dispatched crews with the SAME service category are auto-rejected.
     */
    public function acceptJob(Job $job, User $crew, ?string $notes = null): bool
    {
        return DB::transaction(function () use ($job, $crew, $notes) {
            $crewServiceCategoryId = $crew->crewProfile?->service_category_id;

            // Lock the pivot rows for this job to prevent race conditions
            $lockedRows = DB::table('job_crew')
                ->where('job_id', $job->id)
                ->lockForUpdate()
                ->get();

            $myRow = $lockedRows->firstWhere('crew_user_id', $crew->id);

            // If category-scoped, find the matching row for this crew + category
            if ($crewServiceCategoryId) {
                $myRow = $lockedRows
                    ->where('crew_user_id', $crew->id)
                    ->where('service_category_id', $crewServiceCategoryId)
                    ->first();
            }

            if (! $myRow || $myRow->status !== 'dispatched') {
                return false;
            }

            // Scope: only check for already-accepted in the SAME service category
            $sameCategoryRows = $crewServiceCategoryId
                ? $lockedRows->where('service_category_id', $crewServiceCategoryId)
                : $lockedRows;

            $alreadyAccepted = $sameCategoryRows->firstWhere('status', 'accepted');
            if ($alreadyAccepted) {
                return false;
            }

            // Accept this crew
            DB::table('job_crew')
                ->where('job_id', $job->id)
                ->where('crew_user_id', $crew->id)
                ->where('service_category_id', $crewServiceCategoryId)
                ->update([
                    'status' => 'accepted',
                    'responded_at' => now(),
                    'notes' => $notes,
                ]);

            // Auto-reject all other dispatched crews with the SAME service category
            $otherDispatched = $sameCategoryRows
                ->where('crew_user_id', '!=', $crew->id)
                ->where('status', 'dispatched');

            foreach ($otherDispatched as $otherRow) {
                DB::table('job_crew')
                    ->where('job_id', $job->id)
                    ->where('crew_user_id', $otherRow->crew_user_id)
                    ->where('service_category_id', $otherRow->service_category_id)
                    ->update([
                        'status' => 'rejected',
                        'responded_at' => now(),
                        'notes' => 'Auto-rejected: job accepted by another crew',
                    ]);
            }

            // Update service requirement status
            if ($crewServiceCategoryId) {
                $job->serviceRequirements()
                    ->where('service_category_id', $crewServiceCategoryId)
                    ->where('status', JobServiceRequirement::STATUS_DISPATCHED)
                    ->update(['status' => JobServiceRequirement::STATUS_ACCEPTED]);
            }

            JobActivity::log($job, 'crew_accepted', "{$crew->name} accepted the job", [
                'crew_user_id' => $crew->id,
                'crew_name' => $crew->name,
                'service_category_id' => $crewServiceCategoryId,
            ]);

            if ($otherDispatched->isNotEmpty()) {
                JobActivity::log($job, 'crew_auto_rejected', $otherDispatched->count().' other crew(s) auto-rejected', [
                    'rejected_crew_ids' => $otherDispatched->pluck('crew_user_id')->toArray(),
                    'service_category_id' => $crewServiceCategoryId,
                ]);
            }

            JobAcceptedByCrew::dispatch($job, $crew);

            $this->plannerAutomation->onCrewAccepted($job, $crew);

            return true;
        });
    }

    /**
     * Reject a dispatched job, scoped to the crew's service category.
     */
    public function rejectJob(Job $job, User $crew, ?string $notes = null): bool
    {
        $crewServiceCategoryId = $crew->crewProfile?->service_category_id;

        $query = DB::table('job_crew')
            ->where('job_id', $job->id)
            ->where('crew_user_id', $crew->id)
            ->where('status', 'dispatched');

        if ($crewServiceCategoryId) {
            $query->where('service_category_id', $crewServiceCategoryId);
        }

        $row = $query->first();

        if (! $row) {
            return false;
        }

        DB::table('job_crew')
            ->where('job_id', $job->id)
            ->where('crew_user_id', $crew->id)
            ->where('service_category_id', $row->service_category_id)
            ->update([
                'status' => 'rejected',
                'responded_at' => now(),
                'notes' => $notes,
            ]);

        JobActivity::log($job, 'crew_rejected', "{$crew->name} rejected the job", [
            'crew_user_id' => $crew->id,
            'crew_name' => $crew->name,
            'service_category_id' => $crewServiceCategoryId,
            'notes' => $notes,
        ]);

        $this->plannerAutomation->onCrewRejected($job, $crew);

        return true;
    }

    /**
     * Get jobs dispatched to a crew that are still awaiting response,
     * filtered by the crew's service category.
     *
     * @return Collection<int, Job>
     */
    public function getAvailableJobsForCrew(User $crew): Collection
    {
        $crewServiceCategoryId = $crew->crewProfile?->service_category_id;

        return Job::query()
            ->whereHas('crews', function ($q) use ($crew, $crewServiceCategoryId) {
                $q->where('crew_user_id', $crew->id)
                    ->where('status', 'dispatched');

                if ($crewServiceCategoryId) {
                    $q->where('job_crew.service_category_id', $crewServiceCategoryId);
                }
            })
            ->with(['deal.customer', 'estimate', 'colors'])
            ->active()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get jobs accepted by a crew, filtered by the crew's service category.
     *
     * @return Collection<int, Job>
     */
    public function getAcceptedJobsForCrew(User $crew): Collection
    {
        $crewServiceCategoryId = $crew->crewProfile?->service_category_id;

        return Job::query()
            ->whereHas('crews', function ($q) use ($crew, $crewServiceCategoryId) {
                $q->where('crew_user_id', $crew->id)
                    ->where('status', 'accepted');

                if ($crewServiceCategoryId) {
                    $q->where('job_crew.service_category_id', $crewServiceCategoryId);
                }
            })
            ->with(['deal.customer', 'estimate', 'colors'])
            ->orderBy('scheduled_date')
            ->get();
    }

    /**
     * Replace a crew for a specific service category.
     * Sets old crew's pivot status to 'replaced' and updates requirement back to 'dispatched'.
     */
    public function replaceCrew(Job $job, User $oldCrew, ServiceCategory $category): void
    {
        DB::table('job_crew')
            ->where('job_id', $job->id)
            ->where('crew_user_id', $oldCrew->id)
            ->where('service_category_id', $category->id)
            ->update([
                'status' => 'replaced',
                'responded_at' => now(),
                'notes' => 'Replaced by company',
            ]);

        $job->serviceRequirements()
            ->where('service_category_id', $category->id)
            ->update(['status' => JobServiceRequirement::STATUS_DISPATCHED]);

        JobActivity::log($job, 'crew_replaced', "{$oldCrew->name} replaced for {$category->name}", [
            'crew_user_id' => $oldCrew->id,
            'crew_name' => $oldCrew->name,
            'service_category_id' => $category->id,
        ]);
    }

    /**
     * Remove a crew from a job entirely by hard-deleting the pivot row.
     *
     * If the removed crew was accepted, reverts the service requirement to pending
     * (or dispatched if other dispatched crews remain for the same category).
     * Also removes the crew from the deal's chat thread if they have no other
     * job_crew links to that deal's jobs.
     */
    public function removeCrew(Job $job, User $crew, ?ServiceCategory $category = null): bool
    {
        $query = DB::table('job_crew')
            ->where('job_id', $job->id)
            ->where('crew_user_id', $crew->id);

        if ($category) {
            $query->where('service_category_id', $category->id);
        }

        $row = $query->first();

        if (! $row) {
            return false;
        }

        $previousStatus = $row->status;
        $serviceCategoryId = $row->service_category_id;

        // Hard-delete the pivot row
        DB::table('job_crew')
            ->where('job_id', $job->id)
            ->where('crew_user_id', $crew->id)
            ->where('service_category_id', $serviceCategoryId)
            ->delete();

        // If the removed crew was accepted, revert the service requirement
        if ($previousStatus === 'accepted' && $serviceCategoryId) {
            $hasOtherDispatched = DB::table('job_crew')
                ->where('job_id', $job->id)
                ->where('service_category_id', $serviceCategoryId)
                ->where('status', 'dispatched')
                ->exists();

            $job->serviceRequirements()
                ->where('service_category_id', $serviceCategoryId)
                ->update([
                    'status' => $hasOtherDispatched
                        ? JobServiceRequirement::STATUS_DISPATCHED
                        : JobServiceRequirement::STATUS_PENDING,
                ]);
        }

        // Remove crew from deal chat thread if they have no other job_crew links to this deal's jobs
        $deal = $job->deal;
        if ($deal && $deal->chatThread) {
            $hasOtherJobLinks = DB::table('job_crew')
                ->join('painting_jobs', 'painting_jobs.id', '=', 'job_crew.job_id')
                ->where('painting_jobs.deal_id', $deal->id)
                ->where('job_crew.crew_user_id', $crew->id)
                ->exists();

            if (! $hasOtherJobLinks) {
                $deal->chatThread->removeParticipant($crew);
            }
        }

        JobActivity::log($job, 'crew_removed', "{$crew->name} removed from job", [
            'crew_user_id' => $crew->id,
            'crew_name' => $crew->name,
            'service_category_id' => $serviceCategoryId,
            'previous_status' => $previousStatus,
        ]);

        return true;
    }

    /**
     * Get crews assigned to a job for a specific service category.
     *
     * @return Collection<int, User>
     */
    public function getCrewsForJobByService(Job $job, ServiceCategory $category): Collection
    {
        return $job->crews()
            ->wherePivot('service_category_id', $category->id)
            ->get();
    }
}

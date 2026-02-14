<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class CommandCenterService
{
    public function __construct(
        private PriorityScoreService $priorityService,
    ) {}

    /**
     * Get priority-sorted items needing attention NOW.
     *
     * @return Collection<int, Activity>
     */
    public function getSmartInbox(User $user, int $limit = 15): Collection
    {
        return Activity::query()
            ->forUserOrAssignedTo($user)
            ->incomplete()
            ->notSnoozed()
            ->with(['user', 'assignee', 'subject' => function ($morphTo) {
                $morphTo->morphWith([
                    Deal::class => ['customer'],
                    Job::class => ['deal.customer'],
                    User::class => ['agentProfile'],
                ]);
            }])
            ->where(function ($q) {
                // Items needing attention: overdue, due today, high/urgent priority, or new leads
                $q->where('priority_score', '>', 0)
                    ->orWhere(function ($q2) {
                        $q2->whereNotNull('due_at')
                            ->where('due_at', '<=', now()->endOfDay());
                    })
                    ->orWhereIn('priority', ['high', 'urgent']);
            })
            ->orderByDesc('priority_score')
            ->orderBy('due_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get filtered, paginated activity stream.
     *
     * @param  array{scope?: string, status?: string, type?: string, user_type?: string, time?: string}  $filters
     */
    public function getActivityStream(
        User $user,
        array $filters = [],
        ?string $search = null,
        int $perPage = 20,
    ): LengthAwarePaginator {
        $query = Activity::query()
            ->with(['user', 'assignee', 'subject' => function ($morphTo) {
                $morphTo->morphWith([
                    Deal::class => ['customer'],
                    Job::class => ['deal.customer'],
                    User::class => ['agentProfile'],
                ]);
            }]);

        // Scope filter
        $scope = $filters['scope'] ?? 'mine';
        switch ($scope) {
            case 'mine':
                $query->where(function ($q) use ($user) {
                    $q->where('assigned_to', $user->id)
                        ->orWhere(function ($q2) use ($user) {
                            $q2->whereNull('assigned_to')
                                ->where('user_id', $user->id);
                        });
                });
                break;
            case 'team':
            case 'everyone':
                if (! $user->can('planner.view_all')) {
                    $query->forUserOrAssignedTo($user);
                }
                break;
        }

        // Status filter
        $status = $filters['status'] ?? 'active';
        switch ($status) {
            case 'active':
                $query->incomplete()->notSnoozed();
                break;
            case 'needs_attention':
                $query->incomplete()->notSnoozed()
                    ->where(function ($q) {
                        $q->where('priority_score', '>=', 20)
                            ->orWhere(function ($q2) {
                                $q2->whereNotNull('due_at')
                                    ->where('due_at', '<', now());
                            });
                    });
                break;
            case 'completed':
                $query->completed();
                break;
            case 'snoozed':
                $query->where('snoozed_until', '>', now());
                break;
            case 'all':
                // No status filter
                break;
        }

        // Type filter
        if ($type = $filters['type'] ?? null) {
            $query->ofType($type);
        }

        // User type filter (filter by subject's related user type)
        if ($userType = $filters['user_type'] ?? null) {
            switch ($userType) {
                case 'customer':
                    $query->where(function ($q) {
                        $q->where('subject_type', Customer::class)
                            ->orWhere(function ($q2) {
                                $q2->where('subject_type', Deal::class)
                                    ->whereHas('subject', function ($q3) {
                                        $q3->whereNotNull('customer_id');
                                    });
                            });
                    });
                    break;
                case 'agent':
                    $query->where(function ($q) {
                        $q->where(function ($q2) {
                            $q2->where('subject_type', User::class)
                                ->whereHas('subject', function ($q3) {
                                    $q3->where('type', 'agent');
                                });
                        })->orWhere(function ($q2) {
                            $q2->where('subject_type', Deal::class)
                                ->whereHas('subject', function ($q3) {
                                    $q3->whereNotNull('agent_id');
                                });
                        });
                    });
                    break;
                case 'crew':
                    $query->where('subject_type', User::class)
                        ->whereHas('subject', function ($q) {
                            $q->where('type', 'crew');
                        });
                    break;
            }
        }

        // Time filter
        if ($time = $filters['time'] ?? null) {
            switch ($time) {
                case 'today':
                    $query->whereDate('due_at', today());
                    break;
                case 'this_week':
                    $query->whereBetween('due_at', [now()->startOfWeek(), now()->endOfWeek()]);
                    break;
                case 'this_month':
                    $query->whereBetween('due_at', [now()->startOfMonth(), now()->endOfMonth()]);
                    break;
                case 'overdue':
                    $query->overdue();
                    break;
            }
        }

        // Full-text search
        if ($search) {
            $searchTerm = "%{$search}%";
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', $searchTerm)
                    ->orWhere('description', 'like', $searchTerm);
            });
        }

        return $query
            ->orderByRaw('is_completed ASC')
            ->orderByDesc('priority_score')
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Get full context for the flyout panel about a subject.
     *
     * @return array{subject: Model, activities: Collection, related: array}
     */
    public function getContextForSubject(Model $subject): array
    {
        $context = [
            'subject' => $subject,
            'activities' => collect(),
            'related' => [],
        ];

        if ($subject instanceof Deal) {
            $subject->load(['customer', 'agent', 'assignedTo', 'estimates', 'job', 'property']);

            $context['activities'] = Activity::query()
                ->forSubject($subject)
                ->with(['user', 'assignee'])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();

            $context['related'] = [
                'estimates' => $subject->estimates()->latest()->limit(5)->get(),
                'job' => $subject->job,
            ];
        } elseif ($subject instanceof Job) {
            $subject->load(['deal.customer', 'deal.agent']);

            $context['activities'] = Activity::query()
                ->forSubject($subject)
                ->with(['user', 'assignee'])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();

            $context['related'] = [
                'deal' => $subject->deal,
            ];
        } elseif ($subject instanceof Customer) {
            $subject->load(['user', 'deals', 'properties']);

            $context['activities'] = Activity::query()
                ->forSubject($subject)
                ->with(['user', 'assignee'])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();

            $context['related'] = [
                'deals' => $subject->deals()->latest()->limit(5)->get(),
                'properties' => $subject->properties,
            ];
        } elseif ($subject instanceof User) {
            $context['activities'] = Activity::query()
                ->forSubject($subject)
                ->with(['user', 'assignee'])
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();

            if ($subject->isAgent()) {
                $subject->load('agentProfile');
                $context['related'] = [
                    'deals' => Deal::where('agent_id', $subject->id)->latest()->limit(5)->get(),
                ];
            }
        }

        return $context;
    }

    /**
     * Cross-entity search across deals, jobs, customers, activities.
     */
    public function search(string $query, User $user, int $perPage = 20): LengthAwarePaginator
    {
        return $this->getActivityStream($user, ['status' => 'all'], $query, $perPage);
    }
}

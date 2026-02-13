<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityCalendarController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $activities = Activity::query()
            ->forUserOrAssignedTo(auth()->user())
            ->whereNotNull('due_at')
            ->when($request->start && $request->end, function ($q) use ($request) {
                $q->whereBetween('due_at', [$request->start, $request->end]);
            })
            ->with(['subject' => fn ($m) => $m->morphWith([
                Deal::class => ['customer'],
                Job::class => ['deal.customer'],
                User::class => ['agentProfile'],
            ])])
            ->get()
            ->map(fn (Activity $a) => [
                'id' => $a->id,
                'title' => $a->title,
                'start' => $a->due_at->toIso8601String(),
                'end' => $a->due_at->copy()->addHour()->toIso8601String(),
                'url' => $a->getSubjectUrl() ?? route('planner.index'),
                'color' => match ($a->type) {
                    'meeting' => '#8b5cf6',
                    'call' => '#f59e0b',
                    'task' => '#3b82f6',
                    'email' => '#06b6d4',
                    default => '#6b7280',
                },
                'extendedProps' => [
                    'type' => $a->type,
                    'is_completed' => $a->is_completed,
                    'subject_label' => $a->getSubjectLabel(),
                ],
            ]);

        return response()->json($activities);
    }
}

<?php

namespace App\Services;

use App\Models\Estimate;
use App\Models\Job;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class NotificationRouter
{
    /**
     * Resolve the recipients for a notification permission + entity.
     *
     * @return Collection<int, User>
     */
    public function resolve(string $permission, Model $entity): Collection
    {
        $recipients = collect();

        // 1. Find the assigned user from the entity
        $assignee = $this->resolveAssignedUser($entity);
        if ($assignee && $assignee->isInternal()) {
            $recipients->push($assignee);
        }

        // 2. Find all internal users whose role has this permission
        $roleSubscribers = User::query()
            ->where('type', 'internal')
            ->permission($permission)
            ->get();

        $recipients = $recipients->merge($roleSubscribers);

        // 3. Deduplicate and filter
        return $recipients->unique('id')->filter(fn (User $u) => $u->isInternal())->values();
    }

    /**
     * Resolve the assigned user for an entity by walking the relationship chain.
     */
    private function resolveAssignedUser(Model $entity): ?User
    {
        // Estimate → deal.assignedTo ?? deal.customer.assignedTo
        if ($entity instanceof Estimate) {
            $entity->loadMissing('deal.customer.assignedTo');
            $deal = $entity->deal;

            return $deal?->assignedTo ?? $deal?->customer?->assignedTo;
        }

        // Job → deal.assignedTo ?? deal.customer.assignedTo
        if ($entity instanceof Job) {
            $entity->loadMissing('deal.customer.assignedTo');
            $deal = $entity->deal;

            return $deal?->assignedTo ?? $deal?->customer?->assignedTo;
        }

        // Generic: check for assignedTo relationship
        if (method_exists($entity, 'assignedTo')) {
            return $entity->assignedTo;
        }

        return null;
    }
}

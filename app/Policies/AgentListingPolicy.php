<?php

namespace App\Policies;

use App\Models\AgentListing;
use App\Models\User;

class AgentListingPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAgent() || $user->isInternal();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AgentListing $agentListing): bool
    {
        if ($user->isInternal()) {
            return true;
        }

        return $user->isAgent() && $agentListing->agent_id === $user->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isAgent() || $user->isInternal();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AgentListing $agentListing): bool
    {
        if ($user->isInternal()) {
            return true;
        }

        return $user->isAgent() && $agentListing->agent_id === $user->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AgentListing $agentListing): bool
    {
        if ($user->isInternal()) {
            return true;
        }

        return $user->isAgent() && $agentListing->agent_id === $user->id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AgentListing $agentListing): bool
    {
        if ($user->isInternal()) {
            return true;
        }

        return $user->isAgent() && $agentListing->agent_id === $user->id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AgentListing $agentListing): bool
    {
        return $user->isInternal();
    }
}

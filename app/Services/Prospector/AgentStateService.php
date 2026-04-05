<?php

namespace App\Services\Prospector;

use App\Events\Prospector\AgentStatusChanged;
use App\Models\Prospector\Agent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class AgentStateService
{
    /** Valid agent statuses. */
    private const VALID_STATUSES = [
        'offline',
        'available',
        'nearby',
        'on_call',
        'wrap_up',
        'break',
        'ringing',
    ];

    /** Redis key TTL in seconds (1 hour). */
    private const REDIS_TTL = 3600;

    /**
     * Update agent status in both DB (source of truth) and Redis (fast reads).
     *
     * Broadcasts an AgentStatusChanged event for real-time UI updates.
     */
    public function setStatus(Agent $agent, string $status): void
    {
        if (! in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(
                "Invalid agent status '{$status}'. Must be one of: ".implode(', ', self::VALID_STATUSES)
            );
        }

        $previousStatus = $agent->status;

        // Update ALL agent records for this user (one per company)
        // so status is consistent across the dashboard
        $allAgents = Agent::where('user_id', $agent->user_id)->get();

        foreach ($allAgents as $agentRecord) {
            $agentRecord->update([
                'status' => $status,
                'status_changed_at' => now(),
            ]);

            // Update Redis (fast read path)
            $this->setRedisStatus($agentRecord, $status);
        }

        // Refresh the passed agent instance
        $agent->refresh();

        Log::info('Agent status changed', [
            'agent_id' => $agent->id,
            'user_id' => $agent->user_id,
            'company_id' => $agent->company_id,
            'from' => $previousStatus,
            'to' => $status,
        ]);

        // Broadcast event for real-time UI
        $agent->load('user');
        event(new AgentStatusChanged(
            companyId: $agent->company_id,
            agentId: $agent->id,
            agentName: $agent->user->name ?? 'Unknown',
            oldStatus: $previousStatus,
            newStatus: $status,
        ));
    }

    /**
     * Get agent status from Redis, falling back to DB if cache miss.
     */
    public function getStatus(Agent $agent): string
    {
        $redisKey = $this->getRedisKey($agent);
        $status = Redis::get($redisKey);

        if ($status !== null) {
            return $status;
        }

        // Cache miss — read from DB and repopulate Redis
        $status = $agent->status;
        $this->setRedisStatus($agent, $status);

        return $status;
    }

    /**
     * Check if the agent is available to take calls.
     */
    public function isAvailable(Agent $agent): bool
    {
        return in_array($this->getStatus($agent), ['available', 'nearby'], true);
    }

    /**
     * Get all available agents for a company.
     *
     * Uses DB query (Redis is per-agent, not suited for collection queries).
     *
     * @return Collection<int, Agent>
     */
    public function getAvailableAgents(int $companyId): Collection
    {
        return Agent::where('company_id', $companyId)
            ->where('status', 'available')
            ->get();
    }

    /**
     * Transition agent to wrap-up status.
     *
     * Sets status to 'wrap_up' and dispatches a delayed job
     * to auto-transition back to 'available' after wrap_up_seconds.
     */
    public function transitionToWrapUp(Agent $agent): void
    {
        $this->setStatus($agent, 'wrap_up');

        $wrapUpSeconds = $agent->wrap_up_seconds
            ?? config('prospector.defaults.wrap_up_seconds', 30);

        // Dispatch delayed auto-available transition
        // The job class will be created by the task that builds jobs
        dispatch(function () use ($agent) {
            // Only transition if still in wrap_up (agent may have manually changed)
            $agent->refresh();

            if ($agent->status === 'wrap_up') {
                $this->setStatus($agent, 'available');
            }
        })->delay(now()->addSeconds($wrapUpSeconds));
    }

    /**
     * Reset daily call counts and talk minutes for all agents.
     *
     * Intended to be called by a daily scheduler (e.g., midnight).
     */
    public function resetDailyCounts(): void
    {
        $updated = Agent::where('daily_call_count', '>', 0)
            ->orWhere('daily_talk_minutes', '>', 0)
            ->update([
                'daily_call_count' => 0,
                'daily_talk_minutes' => 0,
            ]);

        Log::info('Agent daily counts reset', ['agents_updated' => $updated]);
    }

    /**
     * Build the Redis key for an agent's status.
     */
    private function getRedisKey(Agent $agent): string
    {
        return "prospector:agent:{$agent->id}:status";
    }

    /**
     * Set the agent status in Redis with TTL.
     */
    private function setRedisStatus(Agent $agent, string $status): void
    {
        try {
            Redis::setex($this->getRedisKey($agent), self::REDIS_TTL, $status);
        } catch (\Exception $e) {
            // Redis is a cache layer — log but don't fail
            Log::warning('Failed to set agent status in Redis', [
                'agent_id' => $agent->id,
                'status' => $status,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

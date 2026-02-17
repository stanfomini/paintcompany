<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\Cache;

class RoundRobinAssign implements ActionInterface
{
    public static function getName(): string
    {
        return 'Round Robin Assign';
    }

    public static function getCategory(): string
    {
        return 'Assignment';
    }

    public static function getDescription(): string
    {
        return 'Assigns the subject to team members in a round-robin rotation.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'user_ids' => [
                'type' => 'string',
                'label' => 'User IDs',
                'description' => 'Comma-separated list of internal user IDs to rotate through.',
                'required' => true,
            ],
            'assignment_field' => [
                'type' => 'string',
                'label' => 'Assignment Field',
                'description' => 'The field name to update on the subject (default: assigned_to).',
                'required' => false,
                'default' => 'assigned_to',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function execute(WorkflowRun $run, array $config): array
    {
        $registry = app(Registry::class);
        $userIdsStr = $registry->resolveDynamicValues($config['user_ids'] ?? '', $run);
        $field = $config['assignment_field'] ?? 'assigned_to';

        $userIds = array_filter(array_map('intval', explode(',', $userIdsStr)));

        if (empty($userIds)) {
            return ['assigned' => false, 'error' => 'No user IDs provided'];
        }

        $cacheKey = 'round_robin:'.md5($run->workflow_id.':'.implode(',', $userIds));
        $currentIndex = Cache::get($cacheKey, 0);
        $selectedUserId = $userIds[$currentIndex % count($userIds)];

        Cache::put($cacheKey, $currentIndex + 1);

        $user = User::find($selectedUserId);

        if (! $user) {
            return ['assigned' => false, 'error' => 'User not found'];
        }

        // Update the subject's assignment field
        $subject = $run->subject;
        if ($subject && in_array($field, $subject->getFillable())) {
            $subject->update([$field => $selectedUserId]);
        }

        // Set in context for downstream actions
        $context = $run->context ?? [];
        $context['assigned_to'] = $selectedUserId;
        $run->update(['context' => $context]);

        return ['assigned' => true, 'assigned_to' => $selectedUserId, 'user_name' => $user->name];
    }
}

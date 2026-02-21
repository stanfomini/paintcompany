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
            'group_type' => [
                'type' => 'string',
                'label' => 'Assignment Group',
                'description' => 'How to determine the pool of users for rotation.',
                'required' => true,
                'default' => 'specific_users',
                'options' => [
                    'specific_users' => 'Specific Users',
                    'role' => 'By User Type/Role',
                ],
            ],
            'user_ids' => [
                'type' => 'string',
                'label' => 'Team Members',
                'description' => 'Select the team members to rotate through.',
                'required' => false,
                'multiple' => true,
                'options' => User::where('type', 'internal')->pluck('name', 'id')->toArray(),
            ],
            'role' => [
                'type' => 'string',
                'label' => 'User Type',
                'description' => 'When group type is "role", assign to users of this type.',
                'required' => false,
                'options' => [
                    'internal' => 'Internal',
                    'agent' => 'Agent',
                ],
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
        $groupType = $config['group_type'] ?? 'specific_users';
        $field = $config['assignment_field'] ?? 'assigned_to';

        $userIds = $this->resolveUserIds($run, $config, $groupType);

        if (empty($userIds)) {
            return ['assigned' => false, 'error' => 'No users available for assignment'];
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

    /**
     * Resolve the pool of user IDs based on group type.
     *
     * @param  array<string, mixed>  $config
     * @return array<int>
     */
    protected function resolveUserIds(WorkflowRun $run, array $config, string $groupType): array
    {
        if ($groupType === 'role') {
            $role = $config['role'] ?? null;

            if (! $role) {
                return [];
            }

            return User::where('type', $role)
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();
        }

        // Default: specific_users
        $registry = app(Registry::class);
        $userIdsStr = $registry->resolveDynamicValues($config['user_ids'] ?? '', $run);

        return array_filter(array_map('intval', explode(',', $userIdsStr)));
    }
}

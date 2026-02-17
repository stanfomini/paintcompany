<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Services\InvitationService;

class InviteToPortal implements ActionInterface
{
    public static function getName(): string
    {
        return 'Invite to Portal';
    }

    public static function getCategory(): string
    {
        return 'Communication';
    }

    public static function getDescription(): string
    {
        return 'Sends a customer portal invitation to the specified email address.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'email' => [
                'type' => 'string',
                'label' => 'Email',
                'description' => 'Email to invite. Use {{contact.email}} for the customer.',
                'required' => true,
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
        $email = $registry->resolveDynamicValues($config['email'] ?? '', $run);

        if (empty($email)) {
            return ['invited' => false, 'error' => 'No email provided'];
        }

        $inviter = User::find($run->triggered_by) ?? User::where('type', 'internal')->first();

        if (! $inviter) {
            return ['invited' => false, 'error' => 'No inviter available'];
        }

        try {
            $service = app(InvitationService::class);
            $service->sendInvitation($inviter, $email, 'customer');

            return ['invited' => true, 'email' => $email];
        } catch (\Throwable $e) {
            return ['invited' => false, 'email' => $email, 'error' => $e->getMessage()];
        }
    }
}

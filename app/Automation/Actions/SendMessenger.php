<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\MessengerConversation;
use App\Models\WorkflowRun;
use App\Services\FacebookMessengerService;

class SendMessenger implements ActionInterface
{
    public static function getName(): string
    {
        return 'Send Messenger';
    }

    public static function getCategory(): string
    {
        return 'Communication';
    }

    public static function getDescription(): string
    {
        return 'Sends a Facebook Messenger message. Supports dynamic values in all fields.';
    }

    public static function getConfigSchema(): array
    {
        return [
            'recipient_id' => [
                'type' => 'string',
                'label' => 'Facebook PSID',
                'description' => 'Leave blank to auto-resolve from customer.',
                'required' => false,
            ],
            'message' => [
                'type' => 'textarea',
                'label' => 'Message',
                'description' => 'Message body. Supports {{dynamic.values}} placeholders.',
                'required' => true,
            ],
        ];
    }

    public function execute(WorkflowRun $run, array $config): array
    {
        $registry = app(Registry::class);

        $psid = isset($config['recipient_id']) && $config['recipient_id'] !== ''
            ? $registry->resolveDynamicValues($config['recipient_id'], $run)
            : null;
        $message = $registry->resolveDynamicValues($config['message'] ?? '', $run);

        // Auto-resolve PSID from customer if not provided
        if (empty($psid)) {
            $psid = $this->resolveCustomerPsid($run);
        }

        if (empty($psid)) {
            return ['sent' => false, 'error' => 'No Facebook PSID found'];
        }

        if (empty($message)) {
            return ['sent' => false, 'error' => 'No message content'];
        }

        $result = app(FacebookMessengerService::class)->sendTextMessage($psid, $message);

        return [
            'sent' => $result['success'] ?? false,
            'psid' => $psid,
            'error' => $result['error'] ?? null,
        ];
    }

    protected function resolveCustomerPsid(WorkflowRun $run): ?string
    {
        $subject = $run->subject;

        if (! $subject) {
            return null;
        }

        $customerId = null;

        if ($subject instanceof Customer) {
            $customerId = $subject->id;
        } elseif ($subject instanceof Deal) {
            $customerId = $subject->customer_id;
        } elseif ($subject instanceof Job) {
            $customerId = $subject->deal?->customer_id;
        }

        if (! $customerId) {
            return null;
        }

        return MessengerConversation::where('customer_id', $customerId)
            ->whereNotNull('facebook_psid')
            ->value('facebook_psid');
    }
}

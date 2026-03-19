<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\Customer;
use App\Models\WorkflowRun;
use App\Services\TwilioService;

class SendSMS implements ActionInterface
{
    public static function getName(): string
    {
        return 'Send SMS';
    }

    public static function getCategory(): string
    {
        return 'Communication';
    }

    public static function getDescription(): string
    {
        return 'Sends an SMS message via Twilio. Supports dynamic values in all fields.';
    }

    public static function getConfigSchema(): array
    {
        return [
            'to' => [
                'type' => 'string',
                'label' => 'Phone Number',
                'description' => 'Recipient phone number. Use {{contact.phone}} for the customer.',
                'required' => true,
            ],
            'message' => [
                'type' => 'textarea',
                'label' => 'Message',
                'description' => 'SMS message body. Supports {{dynamic.values}} placeholders.',
                'required' => true,
            ],
        ];
    }

    public function execute(WorkflowRun $run, array $config): array
    {
        $registry = app(Registry::class);

        $to = $registry->resolveDynamicValues($config['to'] ?? '', $run);
        $message = $registry->resolveDynamicValues($config['message'] ?? '', $run);

        if (empty($to)) {
            return ['sent' => false, 'error' => 'No recipient phone number'];
        }

        if (empty($message)) {
            return ['sent' => false, 'error' => 'No message content'];
        }

        // Marketing consent check: automation SMS is marketing
        $digits = preg_replace('/\D/', '', $to);
        $last10 = substr($digits, -10);
        if (strlen($last10) >= 10) {
            $customer = Customer::where('phone', 'LIKE', '%'.$last10)->first();
            if ($customer && ! $customer->hasSmsMarketingConsent()) {
                return ['sent' => false, 'to' => $to, 'error' => 'Customer lacks SMS marketing consent'];
            }
        }

        $result = app(TwilioService::class)->sendSms($to, $message, ['message_type' => 'marketing']);

        return [
            'sent' => $result['success'] ?? false,
            'to' => $to,
            'error' => $result['error'] ?? null,
        ];
    }
}

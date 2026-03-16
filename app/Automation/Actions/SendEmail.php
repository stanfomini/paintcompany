<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Mail\WorkflowEmail;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\Mail;

class SendEmail implements ActionInterface
{
    public static function getName(): string
    {
        return 'Send Email';
    }

    public static function getCategory(): string
    {
        return 'Communication';
    }

    public static function getDescription(): string
    {
        return 'Sends an email to a specified address. Supports dynamic values in all fields.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'to' => [
                'type' => 'string',
                'label' => 'To Email',
                'description' => 'Recipient email address. Use {{contact.email}} for the customer.',
                'required' => true,
            ],
            'subject' => [
                'type' => 'string',
                'label' => 'Subject',
                'description' => 'Email subject line. Supports {{dynamic.values}} placeholders.',
                'required' => true,
            ],
            'body' => [
                'type' => 'html',
                'label' => 'Body',
                'description' => 'Email body. Use the toolbar to format and insert dynamic values like {{contact.name}}.',
                'required' => true,
            ],
            'from_name' => [
                'type' => 'string',
                'label' => 'From Name',
                'description' => 'Override the sender name (optional).',
                'required' => false,
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

        $to = $registry->resolveDynamicValues($config['to'] ?? '', $run);
        $subject = $registry->resolveDynamicValues($config['subject'] ?? '', $run);
        $body = $registry->resolveDynamicValues($config['body'] ?? '', $run);
        $fromName = isset($config['from_name']) ? $registry->resolveDynamicValues($config['from_name'], $run) : null;

        if (empty($to)) {
            return ['sent' => false, 'error' => 'No recipient email'];
        }

        Mail::to($to)->queue(new WorkflowEmail($subject, $body, $fromName ?: null));

        return ['sent' => true, 'to' => $to];
    }
}

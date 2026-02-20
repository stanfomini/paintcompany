<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Notifications\Notification as BaseNotification;
use Illuminate\Support\Facades\Notification;

class SendNotification implements ActionInterface
{
    public static function getName(): string
    {
        return 'Send Notification';
    }

    public static function getCategory(): string
    {
        return 'Communication';
    }

    public static function getDescription(): string
    {
        return 'Sends a notification to a specific user or the workflow trigger user. Supports dynamic values in the message.';
    }

    /**
     * @return array<string, mixed>
     */
    public static function getConfigSchema(): array
    {
        return [
            'user_id' => [
                'type' => 'string',
                'label' => 'Notify User',
                'description' => 'The user to notify. Leave empty to notify the user who triggered the workflow.',
                'required' => false,
                'default' => null,
                'options' => array_replace(['' => '-- Triggering User --'], User::where('type', 'internal')->pluck('name', 'id')->toArray()),
            ],
            'message' => [
                'type' => 'string',
                'label' => 'Message',
                'description' => 'The notification message. Supports {{dynamic.values}} placeholders.',
                'required' => true,
            ],
            'channels' => [
                'type' => 'array',
                'label' => 'Channels',
                'description' => 'Notification delivery channels.',
                'required' => false,
                'default' => ['database'],
                'options' => ['database', 'mail'],
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

        $userId = $config['user_id'] ?? $run->triggered_by;
        $message = $registry->resolveDynamicValues($config['message'] ?? '', $run);
        $channels = $config['channels'] ?? ['database'];

        $user = User::find($userId);

        if (! $user) {
            return ['notified' => false];
        }

        $user->notify(new WorkflowNotification($message, $channels, $run));

        return ['notified' => true];
    }
}

/**
 * Simple database notification for workflow automation.
 */
class WorkflowNotification extends BaseNotification
{
    /**
     * @param  array<int, string>  $channels
     */
    public function __construct(
        protected string $message,
        protected array $channels,
        protected WorkflowRun $run,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'message' => $this->message,
            'workflow_run_id' => $this->run->id,
            'workflow_id' => $this->run->workflow_id,
            'subject_type' => $this->run->subject_type,
            'subject_id' => $this->run->subject_id,
        ];
    }
}

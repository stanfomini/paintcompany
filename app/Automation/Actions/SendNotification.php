<?php

namespace App\Automation\Actions;

use App\Automation\Contracts\ActionInterface;
use App\Automation\Registry;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification as BaseNotification;

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
            'notify_type' => [
                'type' => 'string',
                'label' => 'Notify',
                'description' => 'Who to notify: a specific user, the assigned user from context, or all users with a role.',
                'required' => false,
                'default' => 'specific_user',
                'options' => [
                    'specific_user' => 'Specific User',
                    'assigned_user' => 'Assigned User',
                    'role' => 'User Role',
                ],
            ],
            'user_id' => [
                'type' => 'string',
                'label' => 'Notify User',
                'description' => 'The user to notify. Leave empty to notify the user who triggered the workflow.',
                'required' => false,
                'default' => null,
                'options' => array_replace(['' => '-- Triggering User --'], User::where('type', 'internal')->pluck('name', 'id')->toArray()),
            ],
            'notify_role' => [
                'type' => 'string',
                'label' => 'User Role',
                'description' => 'Notify all active users with this role. Only used when Notify is set to "User Role".',
                'required' => false,
                'default' => 'internal',
                'options' => [
                    'internal' => 'Internal',
                    'agent' => 'Agent',
                    'customer' => 'Customer',
                    'crew' => 'Crew',
                ],
            ],
            'subject' => [
                'type' => 'string',
                'label' => 'Email Subject',
                'description' => 'Subject line for email channel notifications.',
                'required' => false,
                'default' => 'Workflow Notification',
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

        $message = $registry->resolveDynamicValues($config['message'] ?? '', $run);
        $channels = $config['channels'] ?? ['database'];
        $subject = $config['subject'] ?? 'Workflow Notification';
        $notifyType = $config['notify_type'] ?? 'specific_user';

        $users = $this->resolveRecipients($run, $config, $notifyType);

        if ($users->isEmpty()) {
            return ['notified' => false];
        }

        $notification = new WorkflowNotification($message, $channels, $run, $subject);

        foreach ($users as $user) {
            $user->notify(clone $notification);
        }

        return ['notified' => true, 'recipient_count' => $users->count()];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function resolveRecipients(WorkflowRun $run, array $config, string $notifyType): \Illuminate\Support\Collection
    {
        return match ($notifyType) {
            'assigned_user' => collect([User::find($run->context['assigned_to'] ?? null)])->filter(),
            'role' => User::where('type', $config['notify_role'] ?? 'internal')
                ->where('is_active', true)
                ->get(),
            default => collect([User::find($config['user_id'] ?? $run->triggered_by)])->filter(),
        };
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
        protected string $subject = 'Workflow Notification',
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject ?: 'Workflow Notification')
            ->line($this->message)
            ->line('This notification was sent by an automated workflow.');
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

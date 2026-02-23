<?php

namespace App\Notifications;

use App\Channels\WebPushChannel;
use App\Channels\WebPushMessage;
use App\Models\CompanySetting;
use App\Models\EmployeeProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployeeWelcome extends Notification implements ShouldQueue
{
    use Queueable;

    public string $pushCategory = 'general';

    public function __construct(
        public EmployeeProfile $employeeProfile,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (method_exists($notifiable, 'wantsEmail')) {
            if ($notifiable->wantsEmail($this->pushCategory)) {
                $channels[] = 'mail';
            }
        } else {
            $channels[] = 'mail';
        }

        if (method_exists($notifiable, 'wantsPush') && $notifiable->wantsPush($this->pushCategory)) {
            $channels[] = WebPushChannel::class;
        }

        $channels[] = 'database';
        $channels[] = 'broadcast';

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $companyName = CompanySetting::getInstance()->company_name ?? 'the team';

        $message = (new MailMessage)
            ->subject("Welcome to {$companyName}!")
            ->greeting("Hello {$this->employeeProfile->user->name}!")
            ->line("We're excited to welcome you to {$companyName} as a {$this->employeeProfile->job_title}.")
            ->line('To get started, please log in to complete your onboarding checklist and review any contracts that need your signature.')
            ->action('Log In', url('/login'));

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'employee_profile_id' => $this->employeeProfile->id,
            'message' => "Welcome aboard, {$this->employeeProfile->user->name}!",
        ];
    }

    public function toWebPush(object $notifiable): WebPushMessage
    {
        $companyName = CompanySetting::getInstance()->company_name ?? 'the team';

        return new WebPushMessage(
            title: "Welcome to {$companyName}!",
            body: 'Log in to complete your onboarding checklist',
            url: '/login',
            tag: 'employee-welcome',
        );
    }
}

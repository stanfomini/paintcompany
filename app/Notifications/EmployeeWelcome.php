<?php

namespace App\Notifications;

use App\Models\CompanySetting;
use App\Models\EmployeeProfile;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class EmployeeWelcome extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public EmployeeProfile $employeeProfile,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
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
}

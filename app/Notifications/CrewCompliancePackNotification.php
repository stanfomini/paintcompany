<?php

namespace App\Notifications;

use App\Models\CompanySetting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CrewCompliancePackNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public User $user,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $this->user->load(['crewProfile', 'crewSignedContracts', 'crewW9Form']);

        $pdf = Pdf::loadView('pdf.crew-compliance-pack', [
            'user' => $this->user,
            'settings' => CompanySetting::getInstance(),
        ]);

        $filename = 'compliance-pack-'.str($this->user->name)->slug().'.pdf';

        return (new MailMessage)
            ->subject(__('Crew Compliance Documents - :name', ['name' => $this->user->name]))
            ->line(__('Please find attached the compliance documents for :name.', ['name' => $this->user->name]))
            ->line(__('This pack includes signed contracts, W-9 form details, insurance information, and workers compensation records.'))
            ->attachData($pdf->output(), $filename, ['mime' => 'application/pdf']);
    }
}

<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\EmailMessage;
use App\Models\User;

class WelcomeEmailService
{
    public function __construct(private EmailService $emailService) {}

    /**
     * Send a welcome email from the assignee to the customer.
     */
    public function sendWelcomeEmail(Customer $customer, User $assignee): ?EmailMessage
    {
        if (! $customer->email) {
            return null;
        }

        if (! $assignee->isInternal()) {
            return null;
        }

        if (! $assignee->emailAccount) {
            return null;
        }

        if ($this->hasAlreadySent($customer, $assignee)) {
            return null;
        }

        $subject = 'Welcome to '.config('app.name').'!';
        $bodyHtml = view('emails.customer-welcome', [
            'customer' => $customer,
            'assignee' => $assignee,
        ])->render();

        return $this->emailService->send(
            account: $assignee->emailAccount,
            to: [['address' => $customer->email, 'name' => $customer->full_name]],
            subject: $subject,
            bodyHtml: $bodyHtml,
        );
    }

    /**
     * Check if a welcome email was already sent for this customer-assignee pair.
     */
    private function hasAlreadySent(Customer $customer, User $assignee): bool
    {
        return EmailMessage::query()
            ->where('email_account_id', $assignee->emailAccount->id)
            ->where('direction', 'outbound')
            ->where('subject', 'Welcome to '.config('app.name').'!')
            ->where('to_addresses', 'like', '%'.$customer->email.'%')
            ->exists();
    }
}

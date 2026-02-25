<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;

/**
 * Adds identifying headers to all outgoing notification emails.
 *
 * This allows the inbound email processor to detect when a customer
 * replies to an automated notification (e.g., "Proposal Viewed") and
 * prevent feedback loops where those replies trigger more notifications.
 */
class TagNotificationEmails
{
    public function handle(MessageSending $event): void
    {
        $message = $event->message;

        // Add standard RFC 3834 auto-submitted header
        $message->getHeaders()->addTextHeader('Auto-Submitted', 'auto-generated');

        // Add custom header so our inbound processor can identify replies
        $message->getHeaders()->addTextHeader('X-PM-Notification', 'true');

        // Suppress auto-replies from recipient mail servers
        $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'All');
    }
}

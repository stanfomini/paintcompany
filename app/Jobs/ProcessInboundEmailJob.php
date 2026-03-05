<?php

namespace App\Jobs;

use App\Events\EmailReceived;
use App\Models\Customer;
use App\Models\EmailAccount;
use App\Models\EmailLabel;
use App\Models\EmailMessage;
use App\Models\User;
use App\Notifications\EmailReceivedNotification;
use App\Services\EmailThreadingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessInboundEmailJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(public array $payload)
    {
        $this->onConnection('redis');
    }

    /**
     * Process an inbound email from the Mailgun webhook.
     */
    public function handle(EmailThreadingService $threadingService): void
    {
        // 1. Verify Mailgun signature
        if (! $this->verifySignature()) {
            Log::warning('Email webhook: invalid Mailgun signature');

            return;
        }

        // 2. Find target account by recipient
        $recipient = $this->payload['recipient'] ?? '';
        $localPart = Str::before($recipient, '@');

        $account = EmailAccount::where('address', $localPart)->active()->first();
        if (! $account) {
            Log::info('Email webhook: no account found for '.$recipient);

            return;
        }

        // 3. Deduplicate by Message-Id
        $messageId = $this->payload['Message-Id'] ?? null;
        if ($messageId && EmailMessage::where('message_id', $messageId)->exists()) {
            Log::info('Email webhook: duplicate message '.$messageId);

            return;
        }

        // 4. Thread the message
        $inReplyTo = $this->payload['In-Reply-To'] ?? null;
        $references = isset($this->payload['References'])
            ? preg_split('/\s+/', trim($this->payload['References']))
            : null;
        $subject = $this->payload['subject'] ?? '(no subject)';

        $thread = $threadingService->findOrCreateThread(
            $messageId,
            $inReplyTo,
            $references,
            $subject,
            $account
        );

        // 5. Parse addresses
        $fromAddress = $this->payload['sender'] ?? $this->payload['from'] ?? '';
        $fromName = $this->extractName($this->payload['from'] ?? '');
        $toAddresses = $this->parseAddresses($this->payload['To'] ?? $recipient);
        $ccAddresses = isset($this->payload['Cc']) ? $this->parseAddresses($this->payload['Cc']) : null;

        // 6. Create the message
        $bodyHtml = $this->payload['body-html'] ?? $this->payload['stripped-html'] ?? '';
        $bodyText = $this->payload['body-plain'] ?? $this->payload['stripped-text'] ?? '';

        $message = EmailMessage::create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $account->id,
            'direction' => 'inbound',
            'from_address' => $fromAddress,
            'from_name' => $fromName,
            'to_addresses' => $toAddresses,
            'cc_addresses' => $ccAddresses,
            'bcc_addresses' => null,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'snippet' => Str::limit($bodyText, 200),
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'mailgun_message_id' => $this->payload['message-id'] ?? null,
            'raw_headers' => $this->payload['message-headers'] ?? null,
            'is_read' => false,
            'is_starred' => false,
            'sent_at' => now(),
        ]);

        // 7. Apply inbox label
        $inboxLabel = EmailLabel::where('slug', 'inbox')
            ->whereNull('email_account_id')
            ->first();

        if ($inboxLabel) {
            $message->labels()->attach($inboxLabel->id);
        }

        // 8. Update thread timestamp
        $thread->update(['last_message_at' => now()]);

        // 9. Download attachments if present
        $attachmentCount = (int) ($this->payload['attachment-count'] ?? 0);
        if ($attachmentCount > 0) {
            DownloadEmailAttachmentsJob::dispatch($message, $this->payload);
        }

        // 10. Auto-link to CRM
        AutoLinkEmailJob::dispatch($message);

        // 11. Broadcast
        event(new EmailReceived($message));

        // 12. Push notification (skip for replies to automated notification emails)
        if ($this->isReplyToNotificationEmail($inReplyTo, $references)) {
            Log::info('Email webhook: skipping notification — reply to automated notification email', [
                'message_id' => $messageId,
                'in_reply_to' => $inReplyTo,
            ]);

            return;
        }

        $this->notifyRecipients($account, $message);
    }

    /**
     * Send notifications to the appropriate recipients based on account type.
     *
     * For personal accounts, notifies the account owner.
     * For shared accounts, uses a priority system:
     *   1. Customer's assigned rep (if sender matches a known customer)
     *   2. Default notify user (if no assigned rep found)
     *   3. All members with receives_notifications enabled (excluding already notified)
     */
    private function notifyRecipients(EmailAccount $account, EmailMessage $message): void
    {
        if ($account->isPersonal()) {
            $account->user?->notify(new EmailReceivedNotification($message));

            return;
        }

        $notifiedUserIds = [];

        // Priority 1: Customer's assigned rep (if sender matches a customer)
        $customer = Customer::where('email', $message->from_address)->first();
        if ($customer && $customer->assigned_to) {
            $assignedUser = User::find($customer->assigned_to);
            if ($assignedUser) {
                $assignedUser->notify(new EmailReceivedNotification($message));
                $notifiedUserIds[] = $assignedUser->id;
            }
        }

        // Priority 2: Default notify user (when no assigned rep was found)
        if (empty($notifiedUserIds) && $account->default_notify_user_id) {
            $defaultUser = $account->defaultNotifyUser;
            if ($defaultUser) {
                $defaultUser->notify(new EmailReceivedNotification($message));
                $notifiedUserIds[] = $defaultUser->id;
            }
        }

        // Priority 3 (or additional): All members with receives_notifications
        $members = $account->members()
            ->wherePivot('receives_notifications', true)
            ->whereNotIn('users.id', $notifiedUserIds)
            ->get();

        foreach ($members as $member) {
            $member->notify(new EmailReceivedNotification($message));
        }
    }

    /**
     * Detect if this inbound email is a reply to an automated notification email.
     *
     * Notification emails sent via Laravel's mailer (not EmailService) don't create
     * EmailMessage records. So if In-Reply-To references a message ID with no
     * matching outbound EmailMessage, the sender is replying to a notification.
     *
     * @param  string[]|null  $references
     */
    private function isReplyToNotificationEmail(?string $inReplyTo, ?array $references): bool
    {
        if (! $inReplyTo && empty($references)) {
            return false;
        }

        // Collect all referenced message IDs
        $referencedIds = collect($references ?? []);
        if ($inReplyTo) {
            $referencedIds->push($inReplyTo);
        }
        $referencedIds = $referencedIds->unique()->filter()->values();

        if ($referencedIds->isEmpty()) {
            return false;
        }

        // If ANY referenced message exists as an outbound EmailMessage, this is
        // a reply to a real email conversation — not a notification reply.
        $hasOutboundMatch = EmailMessage::where('direction', 'outbound')
            ->whereIn('message_id', $referencedIds->all())
            ->exists();

        if ($hasOutboundMatch) {
            return false;
        }

        // If there are referenced IDs but NONE match outbound messages,
        // this is likely a reply to a notification email.
        return true;
    }

    /**
     * Verify the Mailgun webhook signature using HMAC-SHA256.
     */
    private function verifySignature(): bool
    {
        $timestamp = $this->payload['timestamp'] ?? '';
        $token = $this->payload['token'] ?? '';
        $signature = $this->payload['signature'] ?? '';
        $secret = config('services.mailgun.webhook_signing_key') ?? config('services.mailgun.secret');

        if (! $secret || ! $timestamp || ! $token || ! $signature) {
            return false;
        }

        $computed = hash_hmac('sha256', $timestamp.$token, $secret);

        return hash_equals($computed, $signature);
    }

    /**
     * Extract the display name from a "Name <email>" formatted string.
     */
    private function extractName(string $from): ?string
    {
        if (preg_match('/^(.+?)\s*</', $from, $matches)) {
            return trim($matches[1], '" ');
        }

        return null;
    }

    /**
     * Parse a comma-separated address list into an array of address/name pairs.
     *
     * @return array<int, array{address: string, name: string|null}>
     */
    private function parseAddresses(string $addresses): array
    {
        $result = [];
        $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $addresses);

        foreach ($parts as $part) {
            $part = trim($part);
            if (preg_match('/<(.+?)>/', $part, $emailMatch)) {
                $name = trim(preg_replace('/<.+?>/', '', $part), ' "');
                $result[] = ['address' => $emailMatch[1], 'name' => $name ?: null];
            } elseif (filter_var($part, FILTER_VALIDATE_EMAIL)) {
                $result[] = ['address' => $part, 'name' => null];
            }
        }

        return $result;
    }
}

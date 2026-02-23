<?php

namespace App\Services;

use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailThread;

class EmailThreadingService
{
    /**
     * Find or create a thread for an incoming/outgoing message.
     *
     * Strategy: In-Reply-To header -> References chain -> subject match (7 day window) -> new thread.
     */
    public function findOrCreateThread(
        ?string $messageId,
        ?string $inReplyTo,
        ?array $references,
        string $subject,
        EmailAccount $account
    ): EmailThread {
        // Strategy 1: In-Reply-To header match
        if ($inReplyTo) {
            $existingMessage = EmailMessage::where('message_id', $inReplyTo)->first();
            if ($existingMessage) {
                return $existingMessage->thread;
            }
        }

        // Strategy 2: References chain match (check any message in the references)
        if ($references) {
            foreach ($references as $ref) {
                $existingMessage = EmailMessage::where('message_id', $ref)->first();
                if ($existingMessage) {
                    return $existingMessage->thread;
                }
            }
        }

        // Strategy 3: Subject-based match (strip Re:/Fwd: and match within 7 days)
        $normalizedSubject = $this->normalizeSubject($subject);
        if ($normalizedSubject) {
            $thread = EmailThread::where('email_account_id', $account->id)
                ->where('subject', 'like', '%'.$normalizedSubject.'%')
                ->where('last_message_at', '>=', now()->subDays(7))
                ->latest('last_message_at')
                ->first();

            if ($thread) {
                return $thread;
            }
        }

        // Strategy 4: Create new thread
        return EmailThread::create([
            'subject' => $subject,
            'original_message_id' => $messageId,
            'email_account_id' => $account->id,
            'last_message_at' => now(),
        ]);
    }

    /**
     * Build the References header for an outbound reply.
     *
     * @return string[]
     */
    public function buildReferencesChain(EmailMessage $replyTo): array
    {
        $references = $replyTo->references ?? [];
        if ($replyTo->message_id) {
            $references[] = $replyTo->message_id;
        }

        return $references;
    }

    /**
     * Strip Re:/Fwd: prefixes and clean subject for matching.
     */
    public function normalizeSubject(string $subject): string
    {
        return trim(preg_replace('/^(re|fwd?|fw):\s*/i', '', $subject));
    }
}

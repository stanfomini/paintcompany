<?php

namespace App\Services;

use App\Events\EmailSent;
use App\Jobs\AutoLinkEmailJob;
use App\Jobs\SendEmailJob;
use App\Models\EmailAccount;
use App\Models\EmailAttachment;
use App\Models\EmailLabel;
use App\Models\EmailMessage;
use App\Models\EmailThread;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmailService
{
    public function __construct(
        private EmailThreadingService $threadingService
    ) {}

    /**
     * Send a new email or reply.
     *
     * @param  array{address: string, name: string|null}[]  $to
     * @param  array{address: string, name: string|null}[]|null  $cc
     * @param  array{address: string, name: string|null}[]|null  $bcc
     * @param  UploadedFile[]  $uploadedFiles
     */
    public function send(
        EmailAccount $account,
        array $to,
        string $subject,
        string $bodyHtml,
        ?array $cc = null,
        ?array $bcc = null,
        ?EmailMessage $replyTo = null,
        array $uploadedFiles = [],
    ): EmailMessage {
        // Generate RFC Message-ID
        $messageId = '<'.Str::uuid().'@'.config('email.domain').'>';

        // Build threading headers
        $inReplyTo = $replyTo?->message_id;
        $references = $replyTo ? $this->threadingService->buildReferencesChain($replyTo) : null;

        // Find or create thread
        $thread = $replyTo
            ? $replyTo->thread
            : EmailThread::create([
                'subject' => $subject,
                'original_message_id' => $messageId,
                'email_account_id' => $account->id,
                'last_message_at' => now(),
            ]);

        // Strip HTML for plain text
        $bodyText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $bodyHtml));

        // Create message
        $message = EmailMessage::create([
            'email_thread_id' => $thread->id,
            'email_account_id' => $account->id,
            'direction' => 'outbound',
            'from_address' => $account->full_address,
            'from_name' => $account->display_name,
            'to_addresses' => $to,
            'cc_addresses' => $cc,
            'bcc_addresses' => $bcc,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'snippet' => Str::limit($bodyText, 200),
            'message_id' => $messageId,
            'in_reply_to' => $inReplyTo,
            'references' => $references,
            'is_read' => true,
            'sent_at' => now(),
        ]);

        // Store uploaded attachments
        foreach ($uploadedFiles as $file) {
            $this->storeAttachment($message, $file);
        }

        // Apply sent label
        $sentLabel = EmailLabel::where('slug', 'sent')->whereNull('email_account_id')->first();
        if ($sentLabel) {
            $message->labels()->attach($sentLabel->id);
        }

        // Update thread timestamp
        $thread->update(['last_message_at' => now()]);

        // Dispatch send job
        SendEmailJob::dispatch($message);

        // Auto-link to CRM
        AutoLinkEmailJob::dispatch($message);

        // Broadcast
        event(new EmailSent($message));

        return $message;
    }

    /**
     * Save or update a draft.
     *
     * @param  array{address: string, name: string|null}[]  $to
     * @param  array{address: string, name: string|null}[]|null  $cc
     * @param  array{address: string, name: string|null}[]|null  $bcc
     */
    public function saveDraft(
        EmailAccount $account,
        ?EmailMessage $existingDraft,
        array $to,
        string $subject,
        string $bodyHtml,
        ?array $cc = null,
        ?array $bcc = null,
        ?EmailMessage $replyTo = null,
    ): EmailMessage {
        $bodyText = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $bodyHtml));

        $data = [
            'email_account_id' => $account->id,
            'direction' => 'draft',
            'from_address' => $account->full_address,
            'from_name' => $account->display_name,
            'to_addresses' => $to,
            'cc_addresses' => $cc,
            'bcc_addresses' => $bcc,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'snippet' => Str::limit($bodyText, 200),
            'in_reply_to' => $replyTo?->message_id,
        ];

        if ($existingDraft) {
            $existingDraft->update($data);

            return $existingDraft->fresh();
        }

        // Create thread for new draft
        $thread = $replyTo
            ? $replyTo->thread
            : EmailThread::create([
                'subject' => $subject ?: '(no subject)',
                'email_account_id' => $account->id,
                'last_message_at' => now(),
            ]);

        $data['email_thread_id'] = $thread->id;
        $message = EmailMessage::create($data);

        // Apply drafts label
        $draftsLabel = EmailLabel::where('slug', 'drafts')->whereNull('email_account_id')->first();
        if ($draftsLabel) {
            $message->labels()->attach($draftsLabel->id);
        }

        return $message;
    }

    /**
     * Send a draft (converts it to outbound).
     *
     * @param  UploadedFile[]  $uploadedFiles
     */
    public function sendDraft(EmailMessage $draft, array $uploadedFiles = []): EmailMessage
    {
        $messageId = '<'.Str::uuid().'@'.config('email.domain').'>';

        $draft->update([
            'direction' => 'outbound',
            'message_id' => $messageId,
            'is_read' => true,
            'sent_at' => now(),
        ]);

        // Store any new attachments
        foreach ($uploadedFiles as $file) {
            $this->storeAttachment($draft, $file);
        }

        // Swap labels: remove drafts, add sent
        $draftsLabel = EmailLabel::where('slug', 'drafts')->whereNull('email_account_id')->first();
        $sentLabel = EmailLabel::where('slug', 'sent')->whereNull('email_account_id')->first();

        if ($draftsLabel) {
            $draft->labels()->detach($draftsLabel->id);
        }
        if ($sentLabel) {
            $draft->labels()->attach($sentLabel->id);
        }

        // Update thread timestamp
        $draft->thread->update(['last_message_at' => now()]);

        // Dispatch send job
        SendEmailJob::dispatch($draft);
        AutoLinkEmailJob::dispatch($draft);
        event(new EmailSent($draft));

        return $draft->fresh();
    }

    /**
     * Move a message to trash.
     */
    public function moveToTrash(EmailMessage $message): void
    {
        // Remove inbox/sent labels
        $removeLabels = EmailLabel::whereNull('email_account_id')
            ->whereIn('slug', ['inbox', 'sent', 'archive', 'starred'])
            ->pluck('id');
        $message->labels()->detach($removeLabels);

        // Add trash label
        $trashLabel = EmailLabel::where('slug', 'trash')->whereNull('email_account_id')->first();
        if ($trashLabel) {
            $message->labels()->syncWithoutDetaching([$trashLabel->id]);
        }
    }

    /**
     * Permanently delete a message (soft delete).
     */
    public function permanentDelete(EmailMessage $message): void
    {
        $message->delete();
    }

    /**
     * Restore a message from trash back to inbox.
     */
    public function restoreFromTrash(EmailMessage $message): void
    {
        $trashLabel = EmailLabel::where('slug', 'trash')->whereNull('email_account_id')->first();
        if ($trashLabel) {
            $message->labels()->detach($trashLabel->id);
        }

        $restoreLabel = $message->direction === 'outbound' ? 'sent' : 'inbox';
        $label = EmailLabel::where('slug', $restoreLabel)->whereNull('email_account_id')->first();
        if ($label) {
            $message->labels()->syncWithoutDetaching([$label->id]);
        }
    }

    /**
     * Store an uploaded file as an email attachment.
     */
    private function storeAttachment(EmailMessage $message, UploadedFile $file): EmailAttachment
    {
        $filename = Str::uuid().'-'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->getClientOriginalExtension();
        $path = 'email-attachments/'.$message->id.'/'.$filename;

        Storage::put($path, file_get_contents($file->getRealPath()));

        return EmailAttachment::create([
            'email_message_id' => $message->id,
            'path' => $path,
            'filename' => $filename,
            'original_filename' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
        ]);
    }
}

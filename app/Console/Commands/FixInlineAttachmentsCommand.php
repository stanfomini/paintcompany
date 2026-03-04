<?php

namespace App\Console\Commands;

use App\Models\EmailAttachment;
use Illuminate\Console\Command;

class FixInlineAttachmentsCommand extends Command
{
    protected $signature = 'email:fix-inline-attachments {--dry-run : Show what would be fixed without making changes}';

    protected $description = 'Fix attachments incorrectly marked as inline when their CID is not referenced in the body HTML';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $attachments = EmailAttachment::where('is_inline', true)
            ->whereNotNull('content_id')
            ->with('message')
            ->get();

        $this->info("Found {$attachments->count()} inline attachments to check.");

        $fixed = 0;

        foreach ($attachments as $attachment) {
            $bodyHtml = $attachment->message?->body_html ?? '';
            $isReferenced = str_contains($bodyHtml, 'cid:'.$attachment->content_id);

            if (! $isReferenced) {
                if ($dryRun) {
                    $this->line("  [DRY RUN] Would fix: {$attachment->original_filename} (ID: {$attachment->id}, message: {$attachment->email_message_id})");
                } else {
                    $attachment->update(['is_inline' => false]);
                    $this->line("  Fixed: {$attachment->original_filename} (ID: {$attachment->id})");
                }
                $fixed++;
            }
        }

        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info("{$prefix}Fixed {$fixed} of {$attachments->count()} inline attachments.");

        return self::SUCCESS;
    }
}

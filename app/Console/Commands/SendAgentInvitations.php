<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Console\Command;

class SendAgentInvitations extends Command
{
    protected $signature = 'invitations:send-agents
        {--emails= : Comma-separated list of email addresses}
        {--inviter= : User ID of the person sending the invitations (must be internal)}
        {--dry-run : Preview what would be sent without actually sending}';

    protected $description = 'Send agent invitation emails to a list of email addresses';

    public function handle(InvitationService $invitationService): int
    {
        $emailsOption = $this->option('emails');
        $inviterId = $this->option('inviter');
        $dryRun = $this->option('dry-run');

        if (! $emailsOption) {
            $this->error('Please provide emails with --emails="email1@example.com,email2@example.com"');

            return self::FAILURE;
        }

        if (! $inviterId) {
            $this->error('Please provide the inviter user ID with --inviter=1');

            return self::FAILURE;
        }

        $inviter = User::find($inviterId);

        if (! $inviter || $inviter->type !== 'internal') {
            $this->error('Inviter must be an internal user.');

            return self::FAILURE;
        }

        $emails = array_map('trim', explode(',', $emailsOption));
        $emails = array_filter($emails, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL));

        if (empty($emails)) {
            $this->error('No valid email addresses found.');

            return self::FAILURE;
        }

        $this->info("Sending agent invitations from: {$inviter->name} ({$inviter->email})");
        $this->info('Total emails: '.count($emails));

        if ($dryRun) {
            $this->warn('DRY RUN - No emails will be sent.');
        }

        $this->newLine();

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($emails as $email) {
            if ($dryRun) {
                $this->line("  [DRY RUN] Would send to: {$email}");
                $sent++;

                continue;
            }

            try {
                $invitationService->sendInvitation($inviter, $email, 'agent');
                $this->info("  Sent: {$email}");
                $sent++;

                // Small delay between sends to avoid rate limiting
                usleep(500_000);
            } catch (\InvalidArgumentException $e) {
                $message = $e->getMessage();

                if (str_contains($message, 'already exists')) {
                    $this->warn("  Skipped (already exists): {$email} - {$message}");
                    $skipped++;
                } else {
                    $this->error("  Failed: {$email} - {$message}");
                    $failed++;
                }
            }
        }

        $this->newLine();
        $this->info("Done! Sent: {$sent}, Skipped: {$skipped}, Failed: {$failed}");

        return self::SUCCESS;
    }
}

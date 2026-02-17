<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Console\Command;

class SendAgentInvitations extends Command
{
    protected $signature = 'invitations:send-agents {--emails= : Comma-separated list of email addresses} {--file= : Path to a text file with one email per line} {--inviter= : User ID of the person sending the invitations (must be internal)} {--dry-run : Preview what would be sent without actually sending}';

    protected $description = 'Send agent invitation emails to a list of email addresses';

    public function handle(InvitationService $invitationService): int
    {
        $dryRun = $this->option('dry-run');

        // Resolve inviter
        $inviterId = $this->option('inviter');

        if (! $inviterId) {
            $internalUsers = User::where('type', 'internal')->pluck('name', 'id')->toArray();

            if (empty($internalUsers)) {
                $this->error('No internal users found.');

                return self::FAILURE;
            }

            $inviterId = $this->choice(
                'Which internal user should send the invitations?',
                $internalUsers,
            );

            // choice() returns the value, need the key
            $inviterId = array_search($inviterId, $internalUsers);
        }

        $inviter = User::find($inviterId);

        if (! $inviter || $inviter->type !== 'internal') {
            $this->error('Inviter must be an internal user.');

            return self::FAILURE;
        }

        // Resolve emails from --emails, --file, or interactive prompt
        $emails = $this->resolveEmails();

        if (empty($emails)) {
            $this->error('No valid email addresses provided.');

            return self::FAILURE;
        }

        $this->info("Sending agent invitations from: {$inviter->name} ({$inviter->email})");
        $this->info('Total emails: '.count($emails));

        if ($dryRun) {
            $this->warn('DRY RUN - No emails will be sent.');
        }

        $this->newLine();

        // Show the list and confirm
        foreach ($emails as $email) {
            $this->line("  - {$email}");
        }

        $this->newLine();

        if (! $dryRun && ! $this->confirm('Send invitations to all '.count($emails).' emails above?')) {
            $this->warn('Cancelled.');

            return self::SUCCESS;
        }

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

    /**
     * Resolve email list from --emails option, --file option, or interactive prompt.
     *
     * @return array<string>
     */
    protected function resolveEmails(): array
    {
        $emailsOption = $this->option('emails');
        $fileOption = $this->option('file');

        // Option 1: --file flag (one email per line)
        if ($fileOption) {
            if (! file_exists($fileOption)) {
                $this->error("File not found: {$fileOption}");

                return [];
            }

            $lines = file($fileOption, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $emails = array_map('trim', $lines);

            return array_values(array_filter($emails, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        }

        // Option 2: --emails flag (comma-separated)
        if ($emailsOption) {
            $emails = array_map('trim', explode(',', $emailsOption));

            return array_values(array_filter($emails, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        }

        // Option 3: Interactive prompt
        $this->info('Enter email addresses one per line. Type "done" when finished:');
        $emails = [];

        while (true) {
            $email = $this->ask('Email (or "done")');

            if (! $email || strtolower($email) === 'done') {
                break;
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $email;
                $this->line("  Added: {$email}");
            } else {
                $this->warn("  Invalid email, skipped: {$email}");
            }
        }

        return $emails;
    }
}

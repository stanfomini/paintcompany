<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Console\Command;

class SendAgentInvitations extends Command
{
    protected $signature = 'invitations:send-agents {--inviter= : User ID of the internal user sending invitations} {--dry-run : Preview without sending}';

    protected $description = 'Send agent invitation emails to the Denver prospecting list';

    /**
     * Denver real estate agents, property managers, and home stagers.
     *
     * @var array<string>
     */
    protected array $agentEmails = [
        'casey.v.carlson@gmail.com',
        'katie.loveco@gmail.com',
        'veracase@comcast.net',
        'a_j_cardinal@yahoo.com',
        'MulderRealEstate@gmail.com',
        'sellwithbrittanie@gmail.com',
        'stephanie@iannonegroup.com',
        'brennangroup@livsothebysrealty.com',
        'ak@theakrileygroup.com',
        'megpitkin@gmail.com',
        'david@schlichterteam.com',
        'Sean@denverrealestatemoguls.com',
        'denver.evolve@gmail.com',
        'hello@divinehometoday.com',
        'contact@pennyandpiperinteriors.com',
    ];

    public function handle(InvitationService $invitationService): int
    {
        $dryRun = $this->option('dry-run');

        // Resolve inviter - default to first internal user if not specified
        $inviterId = $this->option('inviter');

        if (! $inviterId) {
            $inviter = User::where('type', 'internal')->first();
        } else {
            $inviter = User::find($inviterId);
        }

        if (! $inviter || $inviter->type !== 'internal') {
            $this->error('No internal user found. Pass --inviter=ID if needed.');

            return self::FAILURE;
        }

        $this->info("Sending agent invitations from: {$inviter->name} ({$inviter->email})");
        $this->info('Total emails: '.count($this->agentEmails));

        if ($dryRun) {
            $this->warn('DRY RUN - No emails will be sent.');
        }

        $this->newLine();

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->agentEmails as $email) {
            if ($dryRun) {
                $this->line("[DRY RUN] Would send to: {$email}");
                $sent++;

                continue;
            }

            try {
                $invitationService->sendInvitation($inviter, $email, 'agent');
                $this->info("Sent: {$email}");
                $sent++;

                // Small delay between sends to avoid rate limiting
                usleep(500_000);
            } catch (\InvalidArgumentException $e) {
                $message = $e->getMessage();

                if (str_contains($message, 'already exists')) {
                    $this->warn("Skipped: {$email} - {$message}");
                    $skipped++;
                } else {
                    $this->error("Failed: {$email} - {$message}");
                    $failed++;
                }
            }
        }

        $this->newLine();
        $this->info("Done! Sent: {$sent}, Skipped: {$skipped}, Failed: {$failed}");

        return self::SUCCESS;
    }
}

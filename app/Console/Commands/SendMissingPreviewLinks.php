<?php

namespace App\Console\Commands;

use App\Models\ProjectPreview;
use App\Notifications\ProjectPreviewLinkNotification;
use Illuminate\Console\Command;

class SendMissingPreviewLinks extends Command
{
    protected $signature = 'preview-links:send-missing {--dry-run : Preview without sending}';

    protected $description = 'Send preview portal links to customers who never received one';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $query = ProjectPreview::query()
            ->whereNull('preview_link_sent_at')
            ->where('status', 'active')
            ->whereNotNull('deal_id')
            ->whereHas('customer', function ($q) {
                $q->where(function ($q2) {
                    $q2->whereNotNull('email')
                        ->where('email', '!=', '')
                        ->orWhere(function ($q3) {
                            $q3->whereNotNull('phone')
                                ->where('phone', '!=', '');
                        });
                });
            });

        $total = $query->count();

        if ($total === 0) {
            $this->info('No unsent preview links found.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info("[DRY RUN] Would send {$total} preview links.");
            $this->displayBreakdown($query->clone());

            return self::SUCCESS;
        }

        $this->info("Sending {$total} preview links...");

        $sent = ['email_and_sms' => 0, 'email_only' => 0, 'sms_only' => 0, 'failed' => 0];

        $query->with(['customer', 'deal'])
            ->chunkById(50, function ($previews) use (&$sent) {
                foreach ($previews as $preview) {
                    $customer = $preview->customer;

                    if (! $customer) {
                        $sent['failed']++;

                        continue;
                    }

                    try {
                        $customer->notify(new ProjectPreviewLinkNotification($preview, $preview->deal));
                        $preview->update(['preview_link_sent_at' => now()]);

                        $hasEmail = ! empty($customer->email);
                        $hasPhone = ! empty($customer->phone);

                        if ($hasEmail && $hasPhone) {
                            $sent['email_and_sms']++;
                        } elseif ($hasEmail) {
                            $sent['email_only']++;
                        } else {
                            $sent['sms_only']++;
                        }
                    } catch (\Throwable $e) {
                        $sent['failed']++;
                        $this->error("Failed for preview #{$preview->id}: {$e->getMessage()}");
                    }
                }

                sleep(1);
            });

        $totalSent = $sent['email_and_sms'] + $sent['email_only'] + $sent['sms_only'];
        $this->info("Sent {$totalSent} preview links ({$sent['email_and_sms']} email+SMS, {$sent['email_only']} email-only, {$sent['sms_only']} SMS-only)");

        if ($sent['failed'] > 0) {
            $this->warn("{$sent['failed']} failed.");
        }

        return self::SUCCESS;
    }

    private function displayBreakdown($query): void
    {
        $previews = $query->with('customer')->get();

        $emailAndSms = $previews->filter(fn ($p) => ! empty($p->customer?->email) && ! empty($p->customer?->phone))->count();
        $emailOnly = $previews->filter(fn ($p) => ! empty($p->customer?->email) && empty($p->customer?->phone))->count();
        $smsOnly = $previews->filter(fn ($p) => empty($p->customer?->email) && ! empty($p->customer?->phone))->count();

        $this->info("  Email + SMS: {$emailAndSms}");
        $this->info("  Email only:  {$emailOnly}");
        $this->info("  SMS only:    {$smsOnly}");
    }
}

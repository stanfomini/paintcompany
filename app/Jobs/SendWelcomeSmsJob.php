<?php

namespace App\Jobs;

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\ProjectPreview;
use App\Models\User;
use App\Services\TwilioService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendWelcomeSmsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 30;

    public function __construct(
        public string $phone,
        public ?int $customerId = null,
        public ?int $userId = null,
        public ?int $dealId = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $settings = CompanySetting::getInstance();

        // Guard: check if welcome SMS is enabled
        if (! $settings->welcome_sms_enabled) {
            Log::info('Welcome SMS: disabled in settings, skipping', ['phone' => $this->phone]);

            return;
        }

        $customer = $this->customerId ? Customer::find($this->customerId) : null;
        $user = $this->userId ? User::find($this->userId) : null;

        // Guard: idempotency — check if welcome SMS was already sent
        if ($customer && $customer->welcome_sms_sent_at) {
            Log::info('Welcome SMS: already sent to customer, skipping', [
                'phone' => $this->phone,
                'customer_id' => $this->customerId,
            ]);

            return;
        }

        if ($user && $user->welcome_sms_sent_at) {
            Log::info('Welcome SMS: already sent to user, skipping', [
                'phone' => $this->phone,
                'user_id' => $this->userId,
            ]);

            return;
        }

        // Guard: Twilio must be enabled
        $twilio = app(TwilioService::class);
        if (! $twilio->isEnabled()) {
            Log::info('Welcome SMS: Twilio not enabled, skipping', ['phone' => $this->phone]);

            return;
        }

        // Guard: check if phone is opted out
        if ($twilio->isPhoneOptedOut($this->phone)) {
            Log::info('Welcome SMS: phone opted out, skipping', ['phone' => $this->phone]);

            return;
        }

        // Determine the recipient name
        $name = $customer
            ? ($customer->first_name ?: 'there')
            : ($user ? (explode(' ', $user->name)[0] ?? 'there') : 'there');

        $companyName = $settings->company_name ?? 'our company';

        // Build the message based on whether a deal/portal exists
        $message = $this->buildMessage($settings, $name, $companyName);

        Log::info('Welcome SMS: sending', [
            'phone' => $this->phone,
            'customer_id' => $this->customerId,
            'user_id' => $this->userId,
            'deal_id' => $this->dealId,
        ]);

        $twilio->sendSms($this->phone, $message, ['context' => 'welcome_sms']);

        // Mark as sent (idempotency)
        if ($customer) {
            $customer->update(['welcome_sms_sent_at' => now()]);
        }

        if ($user) {
            $user->welcome_sms_sent_at = now();
            $user->save();
        }

        Log::info('Welcome SMS: sent successfully', ['phone' => $this->phone]);
    }

    /**
     * Build the SMS message from templates.
     */
    protected function buildMessage(CompanySetting $settings, string $name, string $companyName): string
    {
        // Try deal-based template first
        if ($this->dealId) {
            $preview = ProjectPreview::where('deal_id', $this->dealId)->first();

            if ($preview) {
                $template = $settings->welcome_sms_with_deal
                    ?: 'Hi {name}! Welcome to {company}. Your project portal is ready — view details, photos, and updates here: {portal_link}';

                return str_replace(
                    ['{name}', '{company}', '{portal_link}'],
                    [$name, $companyName, $preview->getPortalUrl()],
                    $template
                );
            }
        }

        // Without-deal template
        $template = $settings->welcome_sms_without_deal
            ?: 'Hi {name}! Welcome to {company}. We\'re glad to have you! Reply to this message anytime if you need help.';

        return str_replace(
            ['{name}', '{company}'],
            [$name, $companyName],
            $template
        );
    }

    /**
     * Handle a job failure.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::error('Welcome SMS job failed', [
            'phone' => $this->phone,
            'customer_id' => $this->customerId,
            'user_id' => $this->userId,
            'deal_id' => $this->dealId,
            'error' => $exception?->getMessage(),
        ]);
    }
}

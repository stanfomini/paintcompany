<?php

namespace App\Services;

use App\Models\AgentProfile;
use App\Models\CrewProfile;
use App\Models\CustomerProfile;
use App\Models\EmployeeProfile;
use App\Models\SmsLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Twilio\Rest\Client;
use Twilio\Security\RequestValidator;

class TwilioService
{
    protected ?Client $client = null;

    public function isEnabled(): bool
    {
        return (bool) config('services.twilio.enabled')
            && ! empty(config('services.twilio.sid'))
            && ! empty(config('services.twilio.token'))
            && ! empty(config('services.twilio.from'));
    }

    /**
     * Check if a phone number has opted out of SMS.
     */
    public function isPhoneOptedOut(string $phone): bool
    {
        $digits = preg_replace('/\D/', '', $phone);
        $last10 = substr($digits, -10);

        if (strlen($last10) < 10) {
            return false;
        }

        $phoneLike = '%'.$last10;

        $userIds = collect()
            ->merge(User::where('phone', 'LIKE', $phoneLike)->pluck('id'))
            ->merge(CustomerProfile::where('phone', 'LIKE', $phoneLike)->pluck('user_id'))
            ->merge(AgentProfile::where('phone', 'LIKE', $phoneLike)->pluck('user_id'))
            ->merge(CrewProfile::where('phone', 'LIKE', $phoneLike)->pluck('user_id'))
            ->merge(EmployeeProfile::where('phone', 'LIKE', $phoneLike)->pluck('user_id'))
            ->unique()
            ->filter();

        if ($userIds->isEmpty()) {
            return false;
        }

        return User::whereIn('id', $userIds)
            ->whereNotNull('sms_opted_out_at')
            ->exists();
    }

    /**
     * Send an SMS message.
     *
     * @param  array{message_type?: string, user_id?: int, customer_id?: int}  $context
     * @return array{success: bool, sid: string|null, error: string|null}
     */
    public function sendSms(string $to, string $body, array $context = []): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'sid' => null, 'error' => 'Twilio is not enabled'];
        }

        $formatted = $this->formatE164($to);

        if (! $formatted) {
            return ['success' => false, 'sid' => null, 'error' => 'Invalid phone number'];
        }

        // TCPA compliance: block SMS to opted-out phones
        if ($this->isPhoneOptedOut($formatted)) {
            Log::info('SMS blocked: phone opted out', ['to' => $formatted]);
            $this->logSms($formatted, 'outbound', $body, 'blocked', null, 'Phone opted out', $context);

            return ['success' => false, 'sid' => null, 'error' => 'opted_out'];
        }

        // Rate limit: max 5 SMS per phone per hour
        $rateLimitKey = "sms_rate:{$formatted}";
        $currentCount = Cache::get($rateLimitKey, 0);

        if ($currentCount >= 5) {
            Log::warning('SMS rate limit exceeded', ['to' => $formatted]);

            return ['success' => false, 'sid' => null, 'error' => 'Rate limit exceeded'];
        }

        // Daily budget cap
        $dailyKey = 'sms_daily_count:'.now()->format('Y-m-d');
        $dailyCount = Cache::get($dailyKey, 0);
        $dailyLimit = config('services.twilio.daily_limit', 500);

        if ($dailyCount >= $dailyLimit) {
            Log::warning('SMS daily limit exceeded', ['count' => $dailyCount, 'limit' => $dailyLimit]);

            return ['success' => false, 'sid' => null, 'error' => 'Daily SMS limit exceeded'];
        }

        try {
            $options = [
                'from' => config('services.twilio.from'),
                'body' => $body,
            ];

            // Add delivery status callback so we catch carrier-level failures (e.g., 30034)
            try {
                $options['statusCallback'] = route('webhooks.twilio.status');
            } catch (\Throwable) {
                // Route may not exist in test environments
            }

            $message = $this->getClient()->messages->create($formatted, $options);

            // Increment rate counters
            Cache::put($rateLimitKey, $currentCount + 1, now()->addHour());
            Cache::put($dailyKey, $dailyCount + 1, now()->endOfDay());

            $this->logSms($formatted, 'outbound', $body, 'sent', $message->sid, null, $context);

            return ['success' => true, 'sid' => $message->sid, 'error' => null];
        } catch (\Exception $e) {
            Log::error('Failed to send SMS', [
                'to' => $formatted,
                'error' => $e->getMessage(),
            ]);

            $this->logSms($formatted, 'outbound', $body, 'failed', null, $e->getMessage(), $context);

            return ['success' => false, 'sid' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Send an MMS message with media attachments.
     *
     * @param  array<string>  $mediaUrls  URLs of media to attach
     * @return array{success: bool, sid: string|null, error: string|null}
     */
    public function sendMms(string $to, string $body, array $mediaUrls = [], array $context = []): array
    {
        if (! $this->isEnabled()) {
            return ['success' => false, 'sid' => null, 'error' => 'Twilio is not enabled'];
        }

        $formatted = $this->formatE164($to);

        if (! $formatted) {
            return ['success' => false, 'sid' => null, 'error' => 'Invalid phone number'];
        }

        // TCPA compliance: block MMS to opted-out phones
        if ($this->isPhoneOptedOut($formatted)) {
            Log::info('MMS blocked: phone opted out', ['to' => $formatted]);
            $this->logSms($formatted, 'outbound', $body, 'blocked', null, 'Phone opted out', $context);

            return ['success' => false, 'sid' => null, 'error' => 'opted_out'];
        }

        // Same rate limiting as sendSms
        $rateLimitKey = "sms_rate:{$formatted}";
        $currentCount = Cache::get($rateLimitKey, 0);

        if ($currentCount >= 5) {
            Log::warning('MMS rate limit exceeded', ['to' => $formatted]);

            return ['success' => false, 'sid' => null, 'error' => 'Rate limit exceeded'];
        }

        $dailyKey = 'sms_daily_count:'.now()->format('Y-m-d');
        $dailyCount = Cache::get($dailyKey, 0);
        $dailyLimit = config('services.twilio.daily_limit', 500);

        if ($dailyCount >= $dailyLimit) {
            Log::warning('MMS daily limit exceeded', ['count' => $dailyCount, 'limit' => $dailyLimit]);

            return ['success' => false, 'sid' => null, 'error' => 'Daily SMS limit exceeded'];
        }

        try {
            $options = [
                'from' => config('services.twilio.from'),
                'body' => $body,
            ];

            if (! empty($mediaUrls)) {
                $options['mediaUrl'] = $mediaUrls;
            }

            try {
                $options['statusCallback'] = route('webhooks.twilio.status');
            } catch (\Throwable) {
                // Route may not exist in test environments
            }

            $message = $this->getClient()->messages->create($formatted, $options);

            Cache::put($rateLimitKey, $currentCount + 1, now()->addHour());
            Cache::put($dailyKey, $dailyCount + 1, now()->endOfDay());

            $this->logSms($formatted, 'outbound', $body, 'sent', $message->sid, null, $context);

            return ['success' => true, 'sid' => $message->sid, 'error' => null];
        } catch (\Exception $e) {
            Log::error('Failed to send MMS', [
                'to' => $formatted,
                'error' => $e->getMessage(),
            ]);

            $this->logSms($formatted, 'outbound', $body, 'failed', null, $e->getMessage(), $context);

            return ['success' => false, 'sid' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Validate a Twilio webhook signature.
     */
    public function validateWebhookSignature(Request $request): bool
    {
        $token = config('services.twilio.token');

        if (empty($token)) {
            return false;
        }

        $validator = new RequestValidator($token);
        $signature = $request->header('X-Twilio-Signature', '');
        $url = $request->fullUrl();
        $params = $request->all();

        return $validator->validate($signature, $url, $params);
    }

    /**
     * Format a US phone number to E.164 format (+1XXXXXXXXXX).
     */
    public function formatE164(string $phone): ?string
    {
        // Strip all non-digit characters
        $digits = preg_replace('/\D/', '', $phone);

        // Handle 10-digit US number
        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        // Handle 11-digit with leading 1
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        // Already has country code with +
        if (str_starts_with($phone, '+') && strlen($digits) >= 10) {
            return '+'.$digits;
        }

        return null;
    }

    /**
     * Prepend TCPA consent message for first-time SMS.
     */
    public function prependConsentIfNeeded(int $userId, string $body): string
    {
        $user = User::find($userId);

        if (! $user) {
            return $body;
        }

        if ($user->sms_consented_at !== null) {
            return $body;
        }

        $appName = config('app.name');
        $user->update(['sms_consented_at' => now()]);

        return "{$appName} alerts. Reply STOP to opt out. Msg&data rates may apply.\n\n{$body}";
    }

    /**
     * Log an outbound SMS to the audit trail.
     *
     * @param  array{message_type?: string, user_id?: int, customer_id?: int}  $context
     */
    private function logSms(
        string $phone,
        string $direction,
        string $body,
        string $status,
        ?string $twilioSid = null,
        ?string $error = null,
        array $context = []
    ): void {
        try {
            SmsLog::create([
                'phone' => $phone,
                'direction' => $direction,
                'body' => mb_substr($body, 0, 160),
                'status' => $status,
                'message_type' => $context['message_type'] ?? 'service',
                'twilio_sid' => $twilioSid,
                'error' => $error,
                'user_id' => $context['user_id'] ?? null,
                'customer_id' => $context['customer_id'] ?? null,
                'metadata' => ! empty($context) ? $context : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to write SMS audit log', ['error' => $e->getMessage()]);
        }
    }

    public function getClient(): Client
    {
        if (! $this->client) {
            $this->client = new Client(
                config('services.twilio.sid'),
                config('services.twilio.token')
            );
        }

        return $this->client;
    }
}

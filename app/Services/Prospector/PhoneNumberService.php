<?php

namespace App\Services\Prospector;

use App\Models\Prospector\Call;
use App\Models\Prospector\Company;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class PhoneNumberService
{
    /** Valid roles for phone number assignment. */
    private const VALID_ROLES = [
        'unassigned',
        'main',
        'local_presence',
        'caller_id_shadow',
        'shadow',
    ];

    /** Valid inbound call handling actions. */
    private const VALID_INBOUND_ACTIONS = [
        'forward_main',
        'voicemail',
        'ivr',
        'queue',
        'ignore',
    ];

    /**
     * Assign a phone number to a company with a specific role.
     *
     * Validates that:
     * - The number is active
     * - The number isn't already assigned to another company (unless force=true)
     * - Only one number per company can have the 'main' role
     * - caller_id_override is provided when role is 'caller_id_shadow'
     *
     * @throws InvalidArgumentException
     */
    public function assignToCompany(
        PhoneNumber $number,
        Company $company,
        string $role = 'local_presence',
        ?string $callerIdOverride = null,
        bool $force = false,
    ): PhoneNumber {
        $this->validateRole($role);

        if (! $number->is_active) {
            throw new InvalidArgumentException(
                "Cannot assign inactive number {$number->phone_number}. Sync from Twilio or reactivate first."
            );
        }

        // Check if already assigned to a different company
        if ($number->isAssigned() && $number->company_id !== $company->id && ! $force) {
            $existingCompany = $number->company;

            throw new InvalidArgumentException(
                "Number {$number->phone_number} is already assigned to {$existingCompany->name}. "
                .'Use force=true to reassign.'
            );
        }

        // Validate caller_id_override requirement for caller_id_shadow role
        if ($role === 'caller_id_shadow' && empty($callerIdOverride)) {
            throw new InvalidArgumentException(
                'A caller_id_override is required when assigning a number with the caller_id_shadow role.'
            );
        }

        // If assigning as main, demote any existing main number first
        if ($role === 'main') {
            $this->demoteExistingMain($company, $number);
        }

        $result = DB::transaction(function () use ($number, $company, $role, $callerIdOverride) {
            $number->update([
                'company_id' => $company->id,
                'role' => $role,
                'caller_id_override' => $callerIdOverride,
            ]);

            // If this is the main number, update the company reference
            if ($role === 'main') {
                $company->update(['main_phone_number_id' => $number->id]);
            }

            Log::info('Phone number assigned to company', [
                'phone_number_id' => $number->id,
                'phone_number' => $number->phone_number,
                'company_id' => $company->id,
                'company_name' => $company->name,
                'role' => $role,
            ]);

            return $number->refresh();
        });

        // Auto-configure Twilio webhooks for the newly assigned number
        try {
            $this->configureWebhooks($result);
        } catch (\Exception $e) {
            Log::warning('Auto-webhook config failed on assign', [
                'phone_number_id' => $result->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Unassign a phone number from its company.
     *
     * Resets role to 'unassigned', clears caller_id_override and inbound settings.
     * If the number was the company's main number, clears that reference too.
     */
    public function unassign(PhoneNumber $number): PhoneNumber
    {
        if (! $number->isAssigned()) {
            return $number;
        }

        return DB::transaction(function () use ($number) {
            $company = $number->company;
            $wasMain = $number->isMain();

            $number->update([
                'company_id' => null,
                'role' => 'unassigned',
                'caller_id_override' => null,
                'inbound_action' => 'forward_main',
                'inbound_forward_to' => null,
            ]);

            // Clear the company's main_phone_number_id if this was its main number
            if ($wasMain && $company && $company->main_phone_number_id === $number->id) {
                $company->update(['main_phone_number_id' => null]);
            }

            Log::info('Phone number unassigned from company', [
                'phone_number_id' => $number->id,
                'phone_number' => $number->phone_number,
                'company_id' => $company?->id,
                'was_main' => $wasMain,
            ]);

            return $number->refresh();
        });
    }

    /**
     * Set a number as the main number for a company.
     *
     * Demotes any existing main number to 'local_presence'.
     * The number must already be assigned to the company or will be assigned.
     *
     * @throws InvalidArgumentException
     */
    public function setAsMain(PhoneNumber $number, Company $company): PhoneNumber
    {
        if (! $number->is_active) {
            throw new InvalidArgumentException(
                "Cannot set inactive number {$number->phone_number} as main."
            );
        }

        // If the number is assigned to a different company, reject
        if ($number->isAssigned() && $number->company_id !== $company->id) {
            throw new InvalidArgumentException(
                "Number {$number->phone_number} is assigned to another company. Unassign it first."
            );
        }

        return DB::transaction(function () use ($number, $company) {
            // Demote existing main number
            $this->demoteExistingMain($company, $number);

            // Assign and promote
            $number->update([
                'company_id' => $company->id,
                'role' => 'main',
                'caller_id_override' => null,
            ]);

            $company->update(['main_phone_number_id' => $number->id]);

            Log::info('Phone number set as company main', [
                'phone_number_id' => $number->id,
                'phone_number' => $number->phone_number,
                'company_id' => $company->id,
            ]);

            return $number->refresh();
        });
    }

    /**
     * Configure inbound call handling for a phone number.
     *
     * @param  string  $action  One of: forward_main, voicemail, ivr, queue, ignore
     * @param  string|null  $forwardTo  Required when action is forward_main (E.164 format)
     *
     * @throws InvalidArgumentException
     */
    public function configureInbound(
        PhoneNumber $number,
        string $action,
        ?string $forwardTo = null,
    ): PhoneNumber {
        if (! in_array($action, self::VALID_INBOUND_ACTIONS, true)) {
            throw new InvalidArgumentException(
                "Invalid inbound action '{$action}'. Must be one of: ".implode(', ', self::VALID_INBOUND_ACTIONS)
            );
        }

        if ($action === 'forward_main' && empty($forwardTo) && ! $number->isAssigned()) {
            throw new InvalidArgumentException(
                'A forward_to number is required for forward_main action on unassigned numbers.'
            );
        }

        // Validate E.164 format if forwardTo is provided
        if ($forwardTo !== null && ! preg_match('/^\+[1-9]\d{1,14}$/', $forwardTo)) {
            throw new InvalidArgumentException(
                "Invalid forward_to number '{$forwardTo}'. Must be in E.164 format (e.g., +13035551234)."
            );
        }

        $number->update([
            'inbound_action' => $action,
            'inbound_forward_to' => $forwardTo,
        ]);

        Log::info('Phone number inbound config updated', [
            'phone_number_id' => $number->id,
            'phone_number' => $number->phone_number,
            'action' => $action,
            'forward_to' => $forwardTo,
        ]);

        return $number->refresh();
    }

    /**
     * Get unassigned numbers for a Twilio account.
     *
     * Optionally filtered by area code for local presence matching.
     *
     * @return Collection<int, PhoneNumber>
     */
    public function getUnassignedNumbers(int $twilioAccountId, ?string $areaCode = null): Collection
    {
        $query = PhoneNumber::where('twilio_account_id', $twilioAccountId)
            ->whereNull('company_id')
            ->where('is_active', true)
            ->orderBy('area_code')
            ->orderBy('phone_number');

        if ($areaCode !== null) {
            $query->where('area_code', $areaCode);
        }

        return $query->get();
    }

    /**
     * Bulk assign phone numbers to a company.
     *
     * Assigns all specified numbers with the given role.
     * Skips numbers that are already assigned to another company.
     *
     * @param  array<int>  $numberIds
     * @return int Number of successfully assigned numbers
     */
    public function bulkAssign(
        array $numberIds,
        Company $company,
        string $role = 'local_presence',
    ): int {
        $this->validateRole($role);

        if ($role === 'main') {
            throw new InvalidArgumentException(
                'Cannot bulk assign numbers with the main role. Use setAsMain() for a single number.'
            );
        }

        if ($role === 'caller_id_shadow') {
            throw new InvalidArgumentException(
                'Cannot bulk assign numbers with the caller_id_shadow role. Each requires a unique caller_id_override.'
            );
        }

        $numbers = PhoneNumber::whereIn('id', $numberIds)
            ->where('is_active', true)
            ->where(function ($query) use ($company) {
                $query->whereNull('company_id')
                    ->orWhere('company_id', $company->id);
            })
            ->get();

        $assigned = 0;

        DB::transaction(function () use ($numbers, $company, $role, &$assigned) {
            foreach ($numbers as $number) {
                $number->update([
                    'company_id' => $company->id,
                    'role' => $role,
                ]);
                $assigned++;
            }
        });

        Log::info('Bulk phone number assignment completed', [
            'company_id' => $company->id,
            'company_name' => $company->name,
            'requested' => count($numberIds),
            'assigned' => $assigned,
            'role' => $role,
        ]);

        return $assigned;
    }

    /**
     * Get a summary of phone number usage for a company.
     *
     * @return array{total: int, main: int, local_presence: int, shadow: int, caller_id_shadow: int, unassigned: int}
     */
    public function getCompanyNumberSummary(Company $company): array
    {
        $counts = PhoneNumber::where('company_id', $company->id)
            ->where('is_active', true)
            ->selectRaw('role, count(*) as count')
            ->groupBy('role')
            ->pluck('count', 'role')
            ->toArray();

        return [
            'total' => array_sum($counts),
            'main' => $counts['main'] ?? 0,
            'local_presence' => $counts['local_presence'] ?? 0,
            'shadow' => $counts['shadow'] ?? 0,
            'caller_id_shadow' => $counts['caller_id_shadow'] ?? 0,
            'unassigned' => $counts['unassigned'] ?? 0,
        ];
    }

    /**
     * Validate that the given role is one of the allowed values.
     *
     * @throws InvalidArgumentException
     */
    private function validateRole(string $role): void
    {
        if (! in_array($role, self::VALID_ROLES, true)) {
            throw new InvalidArgumentException(
                "Invalid role '{$role}'. Must be one of: ".implode(', ', self::VALID_ROLES)
            );
        }
    }

    /**
     * Search available phone numbers from Twilio for purchase.
     *
     * @return array<int, array{phone_number: string, friendly_name: string, area_code: string, locality: string, region: string}>
     *
     * @throws RuntimeException
     */
    public function searchAvailableNumbers(
        TwilioAccount $account,
        string $areaCode,
        ?string $contains = null,
        int $limit = 20,
    ): array {
        $client = $account->getClient();

        $params = ['areaCode' => $areaCode];

        if ($contains) {
            $params['contains'] = $contains;
        }

        $results = $client->availablePhoneNumbers('US')
            ->local
            ->read($params, $limit);

        return collect($results)->map(fn ($number) => [
            'phone_number' => $number->phoneNumber,
            'friendly_name' => $number->friendlyName,
            'area_code' => substr(preg_replace('/\D/', '', $number->phoneNumber), 1, 3),
            'locality' => $number->locality ?? '',
            'region' => $number->region ?? '',
        ])->all();
    }

    /**
     * Purchase a phone number from Twilio and create the local record.
     *
     * @throws RuntimeException
     */
    public function purchaseNumber(
        TwilioAccount $account,
        string $phoneNumber,
        ?Company $company = null,
        string $role = 'local_presence',
    ): PhoneNumber {
        $client = $account->getClient();

        $purchaseParams = ['phoneNumber' => $phoneNumber];

        // Set the voice/SMS webhook URLs on the purchased number
        $inboundUrl = route('prospector.webhook.twilio.voice.inbound');
        $smsUrl = route('prospector.webhook.twilio.sms.incoming');

        $purchaseParams['voiceUrl'] = $inboundUrl;
        $purchaseParams['voiceMethod'] = 'POST';
        $purchaseParams['smsUrl'] = $smsUrl;
        $purchaseParams['smsMethod'] = 'POST';

        $purchased = $client->incomingPhoneNumbers->create($purchaseParams);

        $digits = preg_replace('/\D/', '', $phoneNumber);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        $areaCode = substr($digits, 0, 3);

        $record = PhoneNumber::create([
            'twilio_account_id' => $account->id,
            'company_id' => $company?->id,
            'phone_number' => $phoneNumber,
            'friendly_name' => $purchased->friendlyName ?? $phoneNumber,
            'area_code' => $areaCode,
            'twilio_sid' => $purchased->sid,
            'role' => $company ? $role : 'unassigned',
            'capabilities' => [
                'voice' => $purchased->capabilities['voice'] ?? true,
                'sms' => $purchased->capabilities['sms'] ?? true,
                'mms' => $purchased->capabilities['mms'] ?? false,
                'fax' => $purchased->capabilities['fax'] ?? false,
            ],
            'is_active' => true,
            'monthly_cost' => $purchased->price ?? null,
        ]);

        if ($company && $role === 'main') {
            $company->update(['main_phone_number_id' => $record->id]);
        }

        Log::info('Phone number purchased from Twilio', [
            'phone_number_id' => $record->id,
            'phone_number' => $phoneNumber,
            'twilio_sid' => $purchased->sid,
            'company_id' => $company?->id,
            'role' => $record->role,
        ]);

        return $record;
    }

    /**
     * Get daily call usage stats for a company's phone numbers over the last N days.
     *
     * @return array<int, array{phone_number_id: int, phone_number: string, date: string, call_count: int}>
     */
    public function getDailyUsageStats(Company $company, int $days = 7): array
    {
        $since = Carbon::now()->subDays($days)->startOfDay();

        return Call::where('company_id', $company->id)
            ->where('started_at', '>=', $since)
            ->whereNotNull('from_number')
            ->selectRaw('from_number, DATE(started_at) as call_date, COUNT(*) as call_count')
            ->groupBy('from_number', 'call_date')
            ->orderBy('call_date')
            ->get()
            ->map(fn ($row) => [
                'from_number' => $row->from_number,
                'date' => $row->call_date,
                'call_count' => (int) $row->call_count,
            ])
            ->all();
    }

    /**
     * Get today's call count per phone number for a company.
     *
     * @return array<string, int> Keyed by phone_number => count
     */
    public function getTodayUsageByNumber(Company $company): array
    {
        return Call::where('company_id', $company->id)
            ->whereDate('started_at', Carbon::today())
            ->whereNotNull('from_number')
            ->selectRaw('from_number, COUNT(*) as call_count')
            ->groupBy('from_number')
            ->pluck('call_count', 'from_number')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Get phone numbers grouped by company and role.
     *
     * @return \Illuminate\Support\Collection<int, array{company: Company, numbers: Collection<int, PhoneNumber>, main: ?PhoneNumber}>
     */
    public function getNumberPoolsByCompany(): \Illuminate\Support\Collection
    {
        $numbers = PhoneNumber::with('company')
            ->where('is_active', true)
            ->whereNotNull('company_id')
            ->orderBy('company_id')
            ->orderByRaw("CASE role WHEN 'main' THEN 0 WHEN 'local_presence' THEN 1 WHEN 'shadow' THEN 2 WHEN 'caller_id_shadow' THEN 3 ELSE 4 END")
            ->orderBy('area_code')
            ->get();

        return $numbers->groupBy('company_id')->map(function ($companyNumbers) {
            $company = $companyNumbers->first()->company;

            return [
                'company' => $company,
                'numbers' => $companyNumbers,
                'main' => $companyNumbers->firstWhere('role', 'main'),
                'local_presence' => $companyNumbers->where('role', 'local_presence')->values(),
                'shadow' => $companyNumbers->where('role', 'shadow')->values(),
                'caller_id_shadow' => $companyNumbers->where('role', 'caller_id_shadow')->values(),
            ];
        })->values();
    }

    /**
     * Get the webhook URLs that Prospector numbers should point to.
     *
     * @return array{voiceUrl: string, voiceMethod: string, smsUrl: string, smsMethod: string, statusCallback: string, statusCallbackMethod: string}
     */
    public function getWebhookUrls(): array
    {
        return [
            'voiceUrl' => route('prospector.webhook.twilio.voice.inbound'),
            'voiceMethod' => 'POST',
            'smsUrl' => route('prospector.webhook.twilio.sms.incoming'),
            'smsMethod' => 'POST',
            'statusCallback' => route('prospector.webhook.twilio.voice.status'),
            'statusCallbackMethod' => 'POST',
        ];
    }

    /**
     * Configure Twilio webhook URLs on a single phone number.
     *
     * Sets voiceUrl, smsUrl, and statusCallback to point to Prospector's endpoints.
     *
     * @return array{phone_number: string, status: string, error?: string}
     */
    public function configureWebhooks(PhoneNumber $number): array
    {
        if (! $number->twilio_sid || ! $number->is_active) {
            return [
                'phone_number' => $number->phone_number,
                'status' => 'skipped',
                'error' => 'No Twilio SID or number inactive',
            ];
        }

        $number->load('twilioAccount');
        $account = $number->twilioAccount;

        if (! $account) {
            return [
                'phone_number' => $number->phone_number,
                'status' => 'error',
                'error' => 'No Twilio account associated',
            ];
        }

        try {
            $client = $account->getClient();
            $urls = $this->getWebhookUrls();

            $client->incomingPhoneNumbers($number->twilio_sid)->update([
                'voiceUrl' => $urls['voiceUrl'],
                'voiceMethod' => $urls['voiceMethod'],
                'smsUrl' => $urls['smsUrl'],
                'smsMethod' => $urls['smsMethod'],
                'statusCallback' => $urls['statusCallback'],
                'statusCallbackMethod' => $urls['statusCallbackMethod'],
            ]);

            Log::info('Configured webhooks on Twilio number', [
                'phone_number_id' => $number->id,
                'phone_number' => $number->phone_number,
                'twilio_sid' => $number->twilio_sid,
            ]);

            return [
                'phone_number' => $number->phone_number,
                'status' => 'configured',
            ];
        } catch (\Exception $e) {
            Log::error('Failed to configure webhooks on Twilio number', [
                'phone_number_id' => $number->id,
                'twilio_sid' => $number->twilio_sid,
                'error' => $e->getMessage(),
            ]);

            return [
                'phone_number' => $number->phone_number,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Configure webhooks on all active numbers for a company.
     *
     * @param  bool  $dryRun  If true, returns what would be configured without calling Twilio
     * @return array<int, array{phone_number: string, status: string, error?: string}>
     */
    public function configureWebhooksForCompany(Company $company, bool $dryRun = false): array
    {
        $numbers = PhoneNumber::where('company_id', $company->id)
            ->where('is_active', true)
            ->whereNotNull('twilio_sid')
            ->get();

        $results = [];

        foreach ($numbers as $number) {
            if ($dryRun) {
                $results[] = [
                    'phone_number' => $number->phone_number,
                    'status' => 'would_configure',
                ];
            } else {
                $results[] = $this->configureWebhooks($number);
            }
        }

        return $results;
    }

    /**
     * Demote an existing main number for a company to local_presence.
     *
     * Skips the given number (the one about to become main).
     */
    private function demoteExistingMain(Company $company, PhoneNumber $excludeNumber): void
    {
        PhoneNumber::where('company_id', $company->id)
            ->where('role', 'main')
            ->where('id', '!=', $excludeNumber->id)
            ->update(['role' => 'local_presence']);
    }
}

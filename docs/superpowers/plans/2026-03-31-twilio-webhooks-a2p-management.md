# Twilio Webhook Auto-Config & A2P 10DLC Management

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Auto-configure Twilio webhooks on Prospector phone numbers so calls/SMS route to our system, and provide an in-app A2P 10DLC compliance dashboard so users never need to visit the Twilio console.

**Architecture:** Two features sharing the same settings area. (1) Webhook auto-config adds a `configureWebhooks()` method to PhoneNumberService that sets voiceUrl/smsUrl on Twilio numbers via their REST API, called automatically on number assignment and manually via a button. (2) A2P management adds a new `TwilioComplianceService` that wraps Twilio's Messaging Services, Brand Registration, and Campaign Registration APIs, surfaced in a new compliance tab on the Prospector settings page.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Flux UI, Twilio REST API (twilio/sdk), Pest tests

**Key conventions:**
- Livewire SFC files use `⚡` prefix, PHP at top, Blade below
- All Prospector tables prefixed `prospector_*`
- Models in `app/Models/Prospector/`, Services in `app/Services/Prospector/`
- Run `vendor/bin/pint --dirty` after PHP changes
- Run `php artisan test --compact --filter=TestName` to verify
- NEVER run `migrate:fresh` or `migrate:refresh`

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `app/Services/Prospector/PhoneNumberService.php` | Modify | Add `configureWebhooks()` and `configureWebhooksForCompany()` |
| `app/Services/Prospector/TwilioComplianceService.php` | Create | A2P brand registration, messaging services, campaign registration, status checks |
| `resources/views/pages/prospector/⚡settings.blade.php` | Modify | Add A2P compliance tab, webhook config buttons |
| `resources/views/pages/prospector/⚡phone-numbers.blade.php` | Modify | Add "Configure Webhooks" button per number |
| `database/migrations/..._create_prospector_messaging_services_table.php` | Create | Track messaging services + brand/campaign registrations |
| `app/Models/Prospector/MessagingService.php` | Create | Model for messaging service records |
| `tests/Feature/Prospector/WebhookConfigTest.php` | Create | Tests for webhook auto-configuration |
| `tests/Feature/Prospector/A2pComplianceTest.php` | Create | Tests for A2P compliance service + UI |

---

### Task 1: Webhook Auto-Configuration Service

**Files:**
- Modify: `app/Services/Prospector/PhoneNumberService.php`
- Test: `tests/Feature/Prospector/WebhookConfigTest.php`

- [ ] **Step 1: Write the failing test for configureWebhooks**

```php
// tests/Feature/Prospector/WebhookConfigTest.php
<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\PhoneNumberService;

it('configures voice and sms webhook urls on a twilio number', function () {
    $account = TwilioAccount::factory()->create();
    $company = Company::factory()->create(['twilio_account_id' => $account->id]);
    $number = PhoneNumber::factory()->create([
        'twilio_account_id' => $account->id,
        'company_id' => $company->id,
        'twilio_sid' => 'PN_TEST_123',
        'is_active' => true,
    ]);

    $service = app(PhoneNumberService::class);

    // Mock the Twilio client — we can't call the real API in tests
    $mockClient = Mockery::mock(\Twilio\Rest\Client::class);
    $mockNumbers = Mockery::mock();
    $mockNumberContext = Mockery::mock();

    $mockClient->incomingPhoneNumbers = $mockNumbers;
    $mockNumbers->shouldReceive('__invoke')
        ->with('PN_TEST_123')
        ->andReturn($mockNumberContext);
    $mockNumberContext->shouldReceive('update')
        ->once()
        ->with(Mockery::on(function ($params) {
            return isset($params['voiceUrl'])
                && isset($params['smsUrl'])
                && str_contains($params['voiceUrl'], '/prospector/webhook/twilio/voice/inbound')
                && str_contains($params['smsUrl'], '/prospector/webhook/twilio/sms/incoming')
                && $params['voiceMethod'] === 'POST'
                && $params['smsMethod'] === 'POST';
        }))
        ->andReturn(true);

    // Bind mock
    $account->shouldReceive('getClient')->andReturn($mockClient);

    // We test the URL generation logic, not the actual API call
    $urls = $service->getWebhookUrls();

    expect($urls)->toHaveKey('voiceUrl')
        ->and($urls)->toHaveKey('smsUrl')
        ->and($urls)->toHaveKey('statusCallback')
        ->and($urls['voiceUrl'])->toContain('/prospector/webhook/twilio/voice/inbound')
        ->and($urls['smsUrl'])->toContain('/prospector/webhook/twilio/sms/incoming')
        ->and($urls['statusCallback'])->toContain('/prospector/webhook/twilio/voice/status');
});

it('returns correct webhook urls for the application', function () {
    $service = app(PhoneNumberService::class);
    $urls = $service->getWebhookUrls();

    expect($urls)->toBeArray()
        ->and($urls)->toHaveKeys(['voiceUrl', 'voiceMethod', 'smsUrl', 'smsMethod', 'statusCallback', 'statusCallbackMethod']);
});

it('configures webhooks for all numbers in a company', function () {
    $account = TwilioAccount::factory()->create();
    $company = Company::factory()->create(['twilio_account_id' => $account->id]);

    PhoneNumber::factory()->count(3)->create([
        'twilio_account_id' => $account->id,
        'company_id' => $company->id,
        'is_active' => true,
    ]);

    $service = app(PhoneNumberService::class);
    $results = $service->configureWebhooksForCompany($company, dryRun: true);

    expect($results)->toHaveCount(3)
        ->and($results[0])->toHaveKey('phone_number')
        ->and($results[0])->toHaveKey('status');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=WebhookConfigTest
```
Expected: FAIL — methods don't exist yet.

- [ ] **Step 3: Implement configureWebhooks in PhoneNumberService**

Add these methods to `app/Services/Prospector/PhoneNumberService.php`:

```php
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
```

- [ ] **Step 4: Auto-configure on number assignment**

Modify the `assignToCompany()` method in `PhoneNumberService.php`. Add webhook configuration after the number is assigned. At the end of the `DB::transaction` closure, after `return $number->refresh();`, add a dispatch to configure asynchronously:

```php
// In assignToCompany(), after the transaction completes, add:
// Auto-configure Twilio webhooks for the newly assigned number
try {
    $this->configureWebhooks($number->refresh());
} catch (\Exception $e) {
    Log::warning('Auto-webhook config failed on assign', [
        'phone_number_id' => $number->id,
        'error' => $e->getMessage(),
    ]);
}
```

Note: This should be OUTSIDE the DB::transaction but still in the method. Restructure the return to capture the result first, configure webhooks, then return.

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --compact --filter=WebhookConfigTest
```
Expected: PASS

- [ ] **Step 6: Run pint and commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Prospector/PhoneNumberService.php tests/Feature/Prospector/WebhookConfigTest.php
git commit -m "feat: auto-configure Twilio webhooks on number assignment"
```

---

### Task 2: Webhook UI Controls

**Files:**
- Modify: `resources/views/pages/prospector/⚡phone-numbers.blade.php`
- Modify: `resources/views/pages/prospector/⚡settings.blade.php`

- [ ] **Step 1: Add configureWebhooks Livewire method to phone-numbers page**

Read the current `⚡phone-numbers.blade.php` file first. Then add these methods to the PHP class:

```php
public ?array $webhookResults = null;

public function configureWebhook(int $numberId): void
{
    $number = PhoneNumber::findOrFail($numberId);
    $service = app(PhoneNumberService::class);
    $result = $service->configureWebhooks($number);
    $this->webhookResults = [$result];
    $this->clearCaches();
}

public function configureAllWebhooks(int $companyId): void
{
    $company = Company::findOrFail($companyId);
    $service = app(PhoneNumberService::class);
    $this->webhookResults = $service->configureWebhooksForCompany($company);
    $this->clearCaches();
}

public function dismissWebhookResults(): void
{
    $this->webhookResults = null;
}
```

- [ ] **Step 2: Add webhook buttons to the phone-numbers page template**

In the pool view, add a "Configure Webhooks" button for each company section header:

```blade
<flux:button size="xs" variant="ghost" icon="link" wire:click="configureAllWebhooks({{ $pool['company']->id }})" wire:loading.attr="disabled">
    Configure Webhooks
</flux:button>
```

Per-number, add a small link icon button:

```blade
<flux:button size="xs" variant="ghost" icon="link" wire:click="configureWebhook({{ $number->id }})" wire:loading.attr="disabled" title="Configure Twilio Webhooks" />
```

Add a results banner at the top of the page showing success/error for each number after configuration.

- [ ] **Step 3: Add webhook URL display to settings page**

Read the current `⚡settings.blade.php`. Add a "Webhook URLs" section showing the URLs that Prospector configures on numbers. This helps users understand what's happening:

Add a computed property:
```php
#[Computed]
public function webhookUrls(): array
{
    return app(PhoneNumberService::class)->getWebhookUrls();
}
```

Add to the template below the accounts list:
```blade
<div class="mt-6 rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
    <flux:heading size="sm" class="mb-3">Webhook URLs</flux:heading>
    <flux:text class="mb-3 text-sm text-zinc-500">These URLs are automatically configured on your Twilio numbers when assigned to a company.</flux:text>
    <div class="space-y-2 font-mono text-xs">
        <div class="flex items-start gap-2">
            <span class="w-24 shrink-0 text-zinc-500">Voice:</span>
            <span class="break-all text-zinc-900 dark:text-zinc-100">{{ $this->webhookUrls['voiceUrl'] }}</span>
        </div>
        <div class="flex items-start gap-2">
            <span class="w-24 shrink-0 text-zinc-500">SMS:</span>
            <span class="break-all text-zinc-900 dark:text-zinc-100">{{ $this->webhookUrls['smsUrl'] }}</span>
        </div>
        <div class="flex items-start gap-2">
            <span class="w-24 shrink-0 text-zinc-500">Status:</span>
            <span class="break-all text-zinc-900 dark:text-zinc-100">{{ $this->webhookUrls['statusCallback'] }}</span>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Run pint and commit**

```bash
vendor/bin/pint --dirty
git add "resources/views/pages/prospector/⚡phone-numbers.blade.php" "resources/views/pages/prospector/⚡settings.blade.php"
git commit -m "feat: webhook configuration UI on phone-numbers and settings pages"
```

---

### Task 3: A2P Compliance Database Schema

**Files:**
- Create: `database/migrations/..._create_prospector_messaging_services_table.php`
- Create: `app/Models/Prospector/MessagingService.php`
- Create: `database/factories/Prospector/MessagingServiceFactory.php`

- [ ] **Step 1: Create migration**

```bash
php artisan make:migration create_prospector_messaging_services_table --no-interaction
```

Edit the migration:

```php
Schema::create('prospector_messaging_services', function (Blueprint $table) {
    $table->id();
    $table->ulid('ulid')->unique();
    $table->foreignId('twilio_account_id')->constrained('prospector_twilio_accounts')->cascadeOnDelete();
    $table->foreignId('company_id')->nullable()->constrained('prospector_companies')->nullOnDelete();

    // Twilio Messaging Service
    $table->string('twilio_sid')->nullable()->unique();
    $table->string('friendly_name');
    $table->string('status')->default('pending'); // pending, active, suspended

    // Brand Registration
    $table->string('brand_sid')->nullable();
    $table->string('brand_status')->nullable(); // PENDING, IN_REVIEW, APPROVED, FAILED
    $table->json('brand_details')->nullable(); // business name, EIN, type, etc.

    // Campaign Registration
    $table->string('campaign_sid')->nullable();
    $table->string('campaign_status')->nullable(); // IN_PROGRESS, VERIFIED, FAILED
    $table->string('campaign_use_case')->nullable(); // MARKETING, MIXED, LOW_VOLUME, etc.
    $table->json('campaign_details')->nullable(); // description, message samples, etc.

    // Number count
    $table->integer('phone_number_count')->default(0);

    $table->timestamps();
});
```

- [ ] **Step 2: Run migration**

```bash
php artisan migrate
```

- [ ] **Step 3: Create model and factory**

```php
// app/Models/Prospector/MessagingService.php
<?php

namespace App\Models\Prospector;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MessagingService extends Model
{
    use HasFactory;

    protected $table = 'prospector_messaging_services';

    protected $fillable = [
        'ulid',
        'twilio_account_id',
        'company_id',
        'twilio_sid',
        'friendly_name',
        'status',
        'brand_sid',
        'brand_status',
        'brand_details',
        'campaign_sid',
        'campaign_status',
        'campaign_use_case',
        'campaign_details',
        'phone_number_count',
    ];

    protected function casts(): array
    {
        return [
            'brand_details' => 'array',
            'campaign_details' => 'array',
            'phone_number_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MessagingService $model) {
            if (empty($model->ulid)) {
                $model->ulid = Str::ulid()->toBase32();
            }
        });
    }

    public function twilioAccount(): BelongsTo
    {
        return $this->belongsTo(TwilioAccount::class, 'twilio_account_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    public function isBrandApproved(): bool
    {
        return $this->brand_status === 'APPROVED';
    }

    public function isCampaignVerified(): bool
    {
        return $this->campaign_status === 'VERIFIED';
    }

    public function isFullyCompliant(): bool
    {
        return $this->isBrandApproved() && $this->isCampaignVerified();
    }

    public function getBrandStatusColor(): string
    {
        return match ($this->brand_status) {
            'APPROVED' => 'green',
            'PENDING', 'IN_REVIEW' => 'amber',
            'FAILED', 'SUSPENDED' => 'red',
            default => 'zinc',
        };
    }

    public function getCampaignStatusColor(): string
    {
        return match ($this->campaign_status) {
            'VERIFIED' => 'green',
            'IN_PROGRESS' => 'amber',
            'FAILED' => 'red',
            default => 'zinc',
        };
    }
}
```

Factory:
```php
// database/factories/Prospector/MessagingServiceFactory.php
<?php

namespace Database\Factories\Prospector;

use App\Models\Prospector\MessagingService;
use App\Models\Prospector\TwilioAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessagingServiceFactory extends Factory
{
    protected $model = MessagingService::class;

    public function definition(): array
    {
        return [
            'twilio_account_id' => TwilioAccount::factory(),
            'friendly_name' => $this->faker->company() . ' Messaging',
            'status' => 'active',
        ];
    }

    public function withBrand(string $status = 'APPROVED'): static
    {
        return $this->state([
            'brand_sid' => 'BN' . $this->faker->regexify('[a-z0-9]{32}'),
            'brand_status' => $status,
            'brand_details' => [
                'business_name' => $this->faker->company(),
                'business_type' => 'LLC',
                'ein' => $this->faker->numerify('##-#######'),
            ],
        ]);
    }

    public function withCampaign(string $status = 'VERIFIED'): static
    {
        return $this->state([
            'campaign_sid' => 'QE' . $this->faker->regexify('[a-z0-9]{32}'),
            'campaign_status' => $status,
            'campaign_use_case' => 'MIXED',
            'campaign_details' => [
                'description' => 'Sales outreach and appointment confirmations',
                'message_samples' => ['Hi {name}, following up on your inquiry.', 'Your appointment is confirmed for {date}.'],
            ],
        ]);
    }

    public function fullyCompliant(): static
    {
        return $this->withBrand('APPROVED')->withCampaign('VERIFIED');
    }
}
```

- [ ] **Step 4: Run pint and commit**

```bash
vendor/bin/pint --dirty
git add database/migrations/*messaging_services* app/Models/Prospector/MessagingService.php database/factories/Prospector/MessagingServiceFactory.php
git commit -m "feat: messaging service model for A2P compliance tracking"
```

---

### Task 4: A2P Compliance Service

**Files:**
- Create: `app/Services/Prospector/TwilioComplianceService.php`
- Test: `tests/Feature/Prospector/A2pComplianceTest.php`

- [ ] **Step 1: Write the failing tests**

```php
// tests/Feature/Prospector/A2pComplianceTest.php
<?php

use App\Models\Prospector\Company;
use App\Models\Prospector\MessagingService;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\TwilioComplianceService;

it('creates a messaging service record', function () {
    $account = TwilioAccount::factory()->create();
    $company = Company::factory()->create(['twilio_account_id' => $account->id]);

    $record = MessagingService::create([
        'twilio_account_id' => $account->id,
        'company_id' => $company->id,
        'friendly_name' => 'Test Messaging Service',
        'status' => 'pending',
    ]);

    expect($record->exists)->toBeTrue()
        ->and($record->ulid)->not()->toBeEmpty()
        ->and($record->friendly_name)->toBe('Test Messaging Service')
        ->and($record->twilioAccount->id)->toBe($account->id)
        ->and($record->company->id)->toBe($company->id);
});

it('tracks brand registration status', function () {
    $service = MessagingService::factory()->withBrand('APPROVED')->create();

    expect($service->isBrandApproved())->toBeTrue()
        ->and($service->getBrandStatusColor())->toBe('green');

    $pending = MessagingService::factory()->withBrand('PENDING')->create();
    expect($pending->isBrandApproved())->toBeFalse()
        ->and($pending->getBrandStatusColor())->toBe('amber');
});

it('tracks campaign registration status', function () {
    $service = MessagingService::factory()->withCampaign('VERIFIED')->create();

    expect($service->isCampaignVerified())->toBeTrue()
        ->and($service->getCampaignStatusColor())->toBe('green');

    $inProgress = MessagingService::factory()->withCampaign('IN_PROGRESS')->create();
    expect($inProgress->isCampaignVerified())->toBeFalse()
        ->and($inProgress->getCampaignStatusColor())->toBe('amber');
});

it('determines full compliance status', function () {
    $compliant = MessagingService::factory()->fullyCompliant()->create();
    expect($compliant->isFullyCompliant())->toBeTrue();

    $partial = MessagingService::factory()->withBrand('APPROVED')->create();
    expect($partial->isFullyCompliant())->toBeFalse();
});

it('compliance service returns messaging services for account', function () {
    $account = TwilioAccount::factory()->create();
    MessagingService::factory()->count(3)->create(['twilio_account_id' => $account->id]);

    $service = app(TwilioComplianceService::class);
    $services = $service->getMessagingServices($account);

    expect($services)->toHaveCount(3);
});

it('compliance service returns compliance summary', function () {
    $account = TwilioAccount::factory()->create();
    MessagingService::factory()->fullyCompliant()->create(['twilio_account_id' => $account->id]);
    MessagingService::factory()->withBrand('PENDING')->create(['twilio_account_id' => $account->id]);

    $service = app(TwilioComplianceService::class);
    $summary = $service->getComplianceSummary($account);

    expect($summary)->toHaveKeys(['total', 'fully_compliant', 'brand_approved', 'campaigns_verified'])
        ->and($summary['total'])->toBe(2)
        ->and($summary['fully_compliant'])->toBe(1)
        ->and($summary['brand_approved'])->toBe(1);
});

it('settings page shows compliance tab', function () {
    $user = \App\Models\User::factory()->create(['type' => 'internal']);

    \Livewire\Livewire::actingAs($user)
        ->test('pages::prospector.settings')
        ->assertSee('A2P Compliance')
        ->assertStatus(200);
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
php artisan test --compact --filter=A2pComplianceTest
```
Expected: FAIL — TwilioComplianceService doesn't exist yet.

- [ ] **Step 3: Create TwilioComplianceService**

```php
// app/Services/Prospector/TwilioComplianceService.php
<?php

namespace App\Services\Prospector;

use App\Models\Prospector\MessagingService;
use App\Models\Prospector\TwilioAccount;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class TwilioComplianceService
{
    /**
     * Get all messaging services for a Twilio account.
     */
    public function getMessagingServices(TwilioAccount $account): Collection
    {
        return MessagingService::where('twilio_account_id', $account->id)
            ->with('company')
            ->orderBy('friendly_name')
            ->get();
    }

    /**
     * Get compliance summary for a Twilio account.
     *
     * @return array{total: int, fully_compliant: int, brand_approved: int, campaigns_verified: int}
     */
    public function getComplianceSummary(TwilioAccount $account): array
    {
        $services = MessagingService::where('twilio_account_id', $account->id)->get();

        return [
            'total' => $services->count(),
            'fully_compliant' => $services->filter->isFullyCompliant()->count(),
            'brand_approved' => $services->filter->isBrandApproved()->count(),
            'campaigns_verified' => $services->filter->isCampaignVerified()->count(),
        ];
    }

    /**
     * Create a messaging service on Twilio and store locally.
     *
     * @throws \RuntimeException
     */
    public function createMessagingService(
        TwilioAccount $account,
        string $friendlyName,
        ?int $companyId = null,
    ): MessagingService {
        $client = $account->getClient();

        try {
            $twilioService = $client->messaging->v1->services->create($friendlyName, [
                'inboundRequestUrl' => route('prospector.webhook.twilio.sms.incoming'),
                'inboundMethod' => 'POST',
                'statusCallback' => route('prospector.webhook.twilio.sms.status'),
                'useInboundWebhookOnNumber' => false,
                'areaCodeGeomatch' => true,
                'stickySender' => true,
            ]);

            $record = MessagingService::create([
                'twilio_account_id' => $account->id,
                'company_id' => $companyId,
                'twilio_sid' => $twilioService->sid,
                'friendly_name' => $friendlyName,
                'status' => 'active',
            ]);

            Log::info('Created Twilio Messaging Service', [
                'messaging_service_id' => $record->id,
                'twilio_sid' => $twilioService->sid,
                'name' => $friendlyName,
            ]);

            return $record;
        } catch (\Exception $e) {
            Log::error('Failed to create Twilio Messaging Service', [
                'name' => $friendlyName,
                'error' => $e->getMessage(),
            ]);

            throw new \RuntimeException("Failed to create messaging service: {$e->getMessage()}", 0, $e);
        }
    }

    /**
     * Add a phone number to a messaging service on Twilio.
     */
    public function addNumberToService(MessagingService $messagingService, \App\Models\Prospector\PhoneNumber $number): bool
    {
        if (! $messagingService->twilio_sid || ! $number->twilio_sid) {
            return false;
        }

        $messagingService->load('twilioAccount');
        $client = $messagingService->twilioAccount->getClient();

        try {
            $client->messaging->v1->services($messagingService->twilio_sid)
                ->phoneNumbers
                ->create($number->twilio_sid);

            $messagingService->increment('phone_number_count');

            Log::info('Added number to messaging service', [
                'messaging_service_id' => $messagingService->id,
                'phone_number' => $number->phone_number,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to add number to messaging service', [
                'messaging_service_id' => $messagingService->id,
                'phone_number' => $number->phone_number,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Refresh brand and campaign statuses from Twilio.
     */
    public function refreshStatus(MessagingService $messagingService): MessagingService
    {
        $messagingService->load('twilioAccount');
        $client = $messagingService->twilioAccount->getClient();

        // Refresh brand status
        if ($messagingService->brand_sid) {
            try {
                $brand = $client->messaging->v1->brandRegistrations($messagingService->brand_sid)->fetch();
                $messagingService->brand_status = $brand->status;
            } catch (\Exception $e) {
                Log::warning('Failed to fetch brand status', [
                    'brand_sid' => $messagingService->brand_sid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Refresh campaign status
        if ($messagingService->campaign_sid && $messagingService->twilio_sid) {
            try {
                $campaign = $client->messaging->v1
                    ->services($messagingService->twilio_sid)
                    ->usAppToPerson($messagingService->campaign_sid)
                    ->fetch();
                $messagingService->campaign_status = $campaign->campaignStatus;
            } catch (\Exception $e) {
                Log::warning('Failed to fetch campaign status', [
                    'campaign_sid' => $messagingService->campaign_sid,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $messagingService->save();

        return $messagingService;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
php artisan test --compact --filter=A2pComplianceTest
```
Expected: PASS (except the settings page test — that's next task)

- [ ] **Step 5: Run pint and commit**

```bash
vendor/bin/pint --dirty
git add app/Services/Prospector/TwilioComplianceService.php tests/Feature/Prospector/A2pComplianceTest.php
git commit -m "feat: A2P compliance service for brand/campaign management"
```

---

### Task 5: A2P Compliance UI on Settings Page

**Files:**
- Modify: `resources/views/pages/prospector/⚡settings.blade.php`

- [ ] **Step 1: Read the current settings page**

Read `resources/views/pages/prospector/⚡settings.blade.php` in full to understand its current structure.

- [ ] **Step 2: Add A2P compliance properties and methods to the PHP class**

```php
use App\Models\Prospector\MessagingService;
use App\Services\Prospector\TwilioComplianceService;

// Add properties
public string $activeTab = 'accounts'; // accounts, compliance, webhooks

// Compliance form
public bool $showCreateServiceModal = false;
public string $serviceName = '';
public ?int $serviceAccountId = null;
public ?int $serviceCompanyId = null;

// Add computed
#[Computed]
public function messagingServices(): \Illuminate\Support\Collection
{
    return MessagingService::with(['twilioAccount', 'company'])->orderBy('friendly_name')->get();
}

// Add methods
public function createMessagingService(): void
{
    $this->validate([
        'serviceName' => 'required|string|min:3|max:100',
        'serviceAccountId' => 'required|exists:prospector_twilio_accounts,id',
    ]);

    $account = TwilioAccount::findOrFail($this->serviceAccountId);
    $service = app(TwilioComplianceService::class);

    try {
        $service->createMessagingService($account, $this->serviceName, $this->serviceCompanyId);
        $this->showCreateServiceModal = false;
        $this->serviceName = '';
        $this->serviceAccountId = null;
        $this->serviceCompanyId = null;
        unset($this->messagingServices);
    } catch (\RuntimeException $e) {
        // Error logged by service
    }
}

public function refreshComplianceStatus(int $serviceId): void
{
    $messagingService = MessagingService::findOrFail($serviceId);
    $service = app(TwilioComplianceService::class);
    $service->refreshStatus($messagingService);
    unset($this->messagingServices);
}

public function addNumberToService(int $serviceId, int $numberId): void
{
    $messagingService = MessagingService::findOrFail($serviceId);
    $number = \App\Models\Prospector\PhoneNumber::findOrFail($numberId);
    $service = app(TwilioComplianceService::class);
    $service->addNumberToService($messagingService, $number);
    unset($this->messagingServices);
}
```

- [ ] **Step 3: Add tabbed UI to the template**

Replace the top-level container with a tab system. Three tabs: Twilio Accounts, A2P Compliance, Webhook URLs.

The **A2P Compliance** tab shows:
- List of messaging services with brand status badge, campaign status badge, number count
- "Create Messaging Service" button → modal with name + account + company selector
- Per-service: "Refresh Status" button, "Add Numbers" expandable section
- Compliance summary card at top: X fully compliant, Y brands approved, Z campaigns verified

The **Webhook URLs** tab shows the webhook URL reference (from Task 2).

Use `flux:tab` components if available, otherwise wire:click to switch `$activeTab`.

- [ ] **Step 4: Run all settings tests**

```bash
php artisan test --compact --filter=A2pComplianceTest
php artisan test --compact --filter=Settings
```
Expected: PASS

- [ ] **Step 5: Run pint and commit**

```bash
vendor/bin/pint --dirty
git add "resources/views/pages/prospector/⚡settings.blade.php"
git commit -m "feat: A2P compliance dashboard on settings page"
```

---

### Task 6: Final Integration Test

**Files:**
- Modify: `tests/Feature/Prospector/WebhookConfigTest.php`
- Modify: `tests/Feature/Prospector/A2pComplianceTest.php`

- [ ] **Step 1: Add integration tests**

```php
// Add to WebhookConfigTest.php
it('phone numbers page has configure webhooks button', function () {
    $user = \App\Models\User::factory()->create(['type' => 'internal']);

    \Livewire\Livewire::actingAs($user)
        ->test('pages::prospector.phone-numbers')
        ->assertSee('Configure Webhooks')
        ->assertStatus(200);
});

// Add to A2pComplianceTest.php
it('settings page compliance tab shows messaging services', function () {
    $user = \App\Models\User::factory()->create(['type' => 'internal']);
    $account = TwilioAccount::factory()->create();
    MessagingService::factory()->fullyCompliant()->create([
        'twilio_account_id' => $account->id,
        'friendly_name' => 'My Messaging Service',
    ]);

    \Livewire\Livewire::actingAs($user)
        ->test('pages::prospector.settings')
        ->set('activeTab', 'compliance')
        ->assertSee('My Messaging Service')
        ->assertSee('APPROVED')
        ->assertStatus(200);
});
```

- [ ] **Step 2: Run full test suite**

```bash
php artisan test --compact --filter=WebhookConfig
php artisan test --compact --filter=A2pCompliance
php artisan test --compact --filter=Prospector 2>&1 | tail -5
```

- [ ] **Step 3: Final pint and commit**

```bash
vendor/bin/pint --dirty
git add tests/Feature/Prospector/WebhookConfigTest.php tests/Feature/Prospector/A2pComplianceTest.php
git commit -m "test: integration tests for webhook config and A2P compliance"
```

---

## Verification

After all tasks:

```bash
# Run all Prospector tests
php artisan test --compact --filter=Prospector

# Verify pint passes
vendor/bin/pint --dirty --test

# Manual verification:
# 1. Visit /prospector/phone-numbers — see "Configure Webhooks" button per company
# 2. Visit /prospector/settings — see Webhook URLs tab with endpoint references
# 3. Visit /prospector/settings — see A2P Compliance tab with messaging service list
# 4. Buy a number — verify webhooks auto-configured
# 5. Assign a number to a company — verify webhooks auto-configured
```

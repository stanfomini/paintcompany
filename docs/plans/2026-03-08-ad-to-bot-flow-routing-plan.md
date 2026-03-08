# Ad-to-Bot-Flow Routing Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Route incoming Facebook Messenger messages to different bot flows based on which ad the user clicked.

**Architecture:** When a click-to-Messenger ad drives a message, Facebook's webhook payload includes `referral.ad_id`. We store a mapping of ad IDs to bot flows in `messenger_ad_flow_mappings`. The messenger settings page gets a new "Ad Routing" section that pulls ads from the Marketing API and lets users assign flows. Messages without ad referral data continue using the default active flow.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Facebook Marketing API (Graph v19.0), Pest tests

**Design doc:** `docs/plans/2026-03-08-ad-to-bot-flow-routing-design.md`

---

### Task 1: Migration — Add `ad_account_id` to `messenger_settings`

**Files:**
- Create: `database/migrations/XXXX_add_ad_account_id_to_messenger_settings_table.php`
- Modify: `app/Models/MessengerSetting.php:17-31` (add to `$fillable`)

**Step 1: Create migration**

```bash
php artisan make:migration add_ad_account_id_to_messenger_settings_table --no-interaction
```

Migration content:

```php
public function up(): void
{
    Schema::table('messenger_settings', function (Blueprint $table) {
        $table->string('ad_account_id')->nullable()->after('page_id');
    });
}

public function down(): void
{
    Schema::table('messenger_settings', function (Blueprint $table) {
        $table->dropColumn('ad_account_id');
    });
}
```

**Step 2: Update MessengerSetting model**

Add `'ad_account_id'` to the `$fillable` array in `app/Models/MessengerSetting.php:17`.

**Step 3: Run migration**

```bash
php artisan migrate
```

**Step 4: Commit**

```bash
git add database/migrations/*ad_account_id* app/Models/MessengerSetting.php
git commit -m "feat: add ad_account_id to messenger_settings"
```

---

### Task 2: Migration + Model — `messenger_ad_flow_mappings`

**Files:**
- Create: `database/migrations/XXXX_create_messenger_ad_flow_mappings_table.php`
- Create: `app/Models/MessengerAdFlowMapping.php`
- Create: `database/factories/MessengerAdFlowMappingFactory.php`

**Step 1: Create model with migration and factory**

```bash
php artisan make:model MessengerAdFlowMapping -mf --no-interaction
```

**Step 2: Write migration**

```php
public function up(): void
{
    Schema::create('messenger_ad_flow_mappings', function (Blueprint $table) {
        $table->id();
        $table->string('facebook_ad_id')->unique()->index();
        $table->foreignId('flow_id')->constrained('messenger_bot_flows')->cascadeOnDelete();
        $table->string('facebook_ad_name')->nullable();
        $table->string('facebook_campaign_name')->nullable();
        $table->string('facebook_adset_name')->nullable();
        $table->string('facebook_ad_status')->nullable();
        $table->timestamp('last_synced_at')->nullable();
        $table->timestamps();
    });
}

public function down(): void
{
    Schema::dropIfExists('messenger_ad_flow_mappings');
}
```

**Step 3: Write model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessengerAdFlowMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'facebook_ad_id',
        'flow_id',
        'facebook_ad_name',
        'facebook_campaign_name',
        'facebook_adset_name',
        'facebook_ad_status',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(MessengerBotFlow::class, 'flow_id');
    }
}
```

**Step 4: Write factory**

```php
<?php

namespace Database\Factories;

use App\Models\MessengerAdFlowMapping;
use App\Models\MessengerBotFlow;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessengerAdFlowMappingFactory extends Factory
{
    protected $model = MessengerAdFlowMapping::class;

    public function definition(): array
    {
        return [
            'facebook_ad_id' => (string) fake()->unique()->numberBetween(100000000, 999999999),
            'flow_id' => MessengerBotFlow::factory(),
            'facebook_ad_name' => fake()->words(3, true).' Ad',
            'facebook_campaign_name' => fake()->words(2, true).' Campaign',
            'facebook_adset_name' => fake()->words(2, true).' AdSet',
            'facebook_ad_status' => fake()->randomElement(['ACTIVE', 'PAUSED']),
            'last_synced_at' => now(),
        ];
    }
}
```

**Step 5: Run migration**

```bash
php artisan migrate
```

**Step 6: Commit**

```bash
git add database/migrations/*messenger_ad_flow_mappings* app/Models/MessengerAdFlowMapping.php database/factories/MessengerAdFlowMappingFactory.php
git commit -m "feat: add MessengerAdFlowMapping model, migration, factory"
```

---

### Task 3: FacebookMarketingService — Read Ads from API

**Files:**
- Create: `app/Services/FacebookMarketingService.php`
- Test: `tests/Feature/Messenger/FacebookMarketingServiceTest.php`

**Step 1: Write the failing test**

```php
<?php

use App\Models\MessengerSetting;
use App\Services\FacebookMarketingService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'page_access_token' => 'test-token',
        'ad_account_id' => 'act_123456',
    ]);
    MessengerSetting::clearInstanceCache();
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

it('fetches ads from the Marketing API', function () {
    Http::fake([
        'graph.facebook.com/v19.0/act_123456/ads*' => Http::response([
            'data' => [
                [
                    'id' => '111',
                    'name' => 'Kitchen Ad',
                    'status' => 'ACTIVE',
                    'campaign' => ['id' => 'c1', 'name' => 'Interior Campaign'],
                    'adset' => ['id' => 'as1', 'name' => 'Denver Homeowners'],
                ],
                [
                    'id' => '222',
                    'name' => 'Deck Ad',
                    'status' => 'PAUSED',
                    'campaign' => ['id' => 'c2', 'name' => 'Exterior Campaign'],
                    'adset' => ['id' => 'as2', 'name' => 'Suburban'],
                ],
            ],
        ]),
    ]);

    $service = app(FacebookMarketingService::class);
    $ads = $service->fetchAds();

    expect($ads)->toHaveCount(2);
    expect($ads[0]['id'])->toBe('111');
    expect($ads[0]['name'])->toBe('Kitchen Ad');
    expect($ads[0]['campaign']['name'])->toBe('Interior Campaign');
});

it('returns empty array when ad account is not configured', function () {
    MessengerSetting::getInstance()->update(['ad_account_id' => null]);
    MessengerSetting::clearInstanceCache();

    $service = app(FacebookMarketingService::class);
    $ads = $service->fetchAds();

    expect($ads)->toBeEmpty();
});

it('returns empty array on API error', function () {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'Invalid token']], 401),
    ]);

    $service = app(FacebookMarketingService::class);
    $ads = $service->fetchAds();

    expect($ads)->toBeEmpty();
});

it('handles pagination', function () {
    Http::fake([
        'graph.facebook.com/v19.0/act_123456/ads*' => Http::sequence()
            ->push([
                'data' => [
                    ['id' => '111', 'name' => 'Ad 1', 'status' => 'ACTIVE', 'campaign' => ['id' => 'c1', 'name' => 'C1'], 'adset' => ['id' => 'as1', 'name' => 'AS1']],
                ],
                'paging' => ['next' => 'https://graph.facebook.com/v19.0/act_123456/ads?after=cursor1'],
            ])
            ->push([
                'data' => [
                    ['id' => '222', 'name' => 'Ad 2', 'status' => 'ACTIVE', 'campaign' => ['id' => 'c1', 'name' => 'C1'], 'adset' => ['id' => 'as1', 'name' => 'AS1']],
                ],
            ]),
    ]);

    $service = app(FacebookMarketingService::class);
    $ads = $service->fetchAds();

    expect($ads)->toHaveCount(2);
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact tests/Feature/Messenger/FacebookMarketingServiceTest.php
```

Expected: FAIL — class not found

**Step 3: Write the service**

Create `app/Services/FacebookMarketingService.php`:

```php
<?php

namespace App\Services;

use App\Models\MessengerAdFlowMapping;
use App\Models\MessengerSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FacebookMarketingService
{
    protected const GRAPH_API_URL = 'https://graph.facebook.com/v19.0';

    /**
     * Fetch all ads from the configured ad account.
     *
     * @return array<int, array{id: string, name: string, status: string, campaign: array, adset: array}>
     */
    public function fetchAds(): array
    {
        $settings = MessengerSetting::getInstance();

        if (empty($settings->ad_account_id) || empty($settings->page_access_token)) {
            return [];
        }

        $ads = [];
        $url = self::GRAPH_API_URL . '/' . $settings->ad_account_id . '/ads';
        $params = [
            'fields' => 'id,name,status,campaign{id,name},adset{id,name}',
            'access_token' => $settings->page_access_token,
            'limit' => 100,
        ];

        try {
            // First page
            $response = Http::get($url, $params);

            if (! $response->successful()) {
                Log::warning('FacebookMarketing: Failed to fetch ads', [
                    'status' => $response->status(),
                    'error' => $response->json('error.message', 'Unknown error'),
                ]);

                return [];
            }

            $ads = array_merge($ads, $response->json('data', []));

            // Follow pagination
            $nextUrl = $response->json('paging.next');
            $maxPages = 10;
            $page = 1;

            while ($nextUrl && $page < $maxPages) {
                $response = Http::get($nextUrl);

                if (! $response->successful()) {
                    break;
                }

                $ads = array_merge($ads, $response->json('data', []));
                $nextUrl = $response->json('paging.next');
                $page++;
            }
        } catch (\Throwable $e) {
            Log::error('FacebookMarketing: Exception fetching ads', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $ads;
    }

    /**
     * Sync ads from the Marketing API into local mappings table.
     * Creates new mapping records for new ads, updates cached metadata for existing ones.
     * Does NOT remove mappings for ads that no longer exist (preserves flow assignments).
     *
     * @return int Number of ads synced
     */
    public function syncAds(): int
    {
        $ads = $this->fetchAds();

        foreach ($ads as $ad) {
            MessengerAdFlowMapping::updateOrCreate(
                ['facebook_ad_id' => $ad['id']],
                [
                    'facebook_ad_name' => $ad['name'] ?? null,
                    'facebook_campaign_name' => $ad['campaign']['name'] ?? null,
                    'facebook_adset_name' => $ad['adset']['name'] ?? null,
                    'facebook_ad_status' => $ad['status'] ?? null,
                    'last_synced_at' => now(),
                ]
            );
        }

        return count($ads);
    }
}
```

**Step 4: Run test to verify it passes**

```bash
php artisan test --compact tests/Feature/Messenger/FacebookMarketingServiceTest.php
```

Expected: PASS

**Step 5: Commit**

```bash
git add app/Services/FacebookMarketingService.php tests/Feature/Messenger/FacebookMarketingServiceTest.php
git commit -m "feat: add FacebookMarketingService to fetch ads from Marketing API"
```

---

### Task 4: Ad-Based Flow Routing in Webhook Pipeline

**Files:**
- Modify: `app/Services/FacebookMessengerService.php:149-166` (processIncomingMessage), `:171-213` (handleMessage), `:218-252` (processPostback)
- Modify: `app/Services/MessengerBotFlowService.php:22-52` (startConversation)
- Test: `tests/Feature/Messenger/AdFlowRoutingTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/Messenger/AdFlowRoutingTest.php`:

```php
<?php

use App\Models\MessengerAdFlowMapping;
use App\Models\MessengerBotFlow;
use App\Models\MessengerBotStep;
use App\Models\MessengerConversation;
use App\Models\MessengerSetting;
use App\Services\FacebookMessengerService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'verify_token' => 'test-verify-token',
        'app_secret' => 'test-app-secret',
        'page_access_token' => 'test-page-token',
        'page_id' => '123456',
    ]);
    MessengerSetting::clearInstanceCache();

    Http::fake([
        'graph.facebook.com/*' => Http::response(['message_id' => 'mid.test']),
    ]);
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

it('routes message to mapped flow when referral ad_id is present', function () {
    // Create two flows
    $defaultFlow = MessengerBotFlow::factory()->active()->create(['name' => 'Default Flow']);
    MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $defaultFlow->id,
        'sort_order' => 1,
        'message_text' => 'Default question?',
    ]);

    $adFlow = MessengerBotFlow::factory()->create(['name' => 'Ad Flow', 'status' => 'draft']);
    MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
        'flow_id' => $adFlow->id,
        'sort_order' => 1,
        'message_text' => 'Ad-specific question?',
    ]);

    // Map ad to flow
    MessengerAdFlowMapping::factory()->create([
        'facebook_ad_id' => '999888',
        'flow_id' => $adFlow->id,
    ]);

    // Simulate webhook with referral
    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'psid_ad_user'],
            'message' => [
                'mid' => 'mid.abc',
                'text' => 'Hi',
            ],
            'referral' => [
                'source' => 'ADS',
                'ad_id' => '999888',
            ],
        ]],
    ]);

    $conversation = MessengerConversation::where('facebook_psid', 'psid_ad_user')->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->flow_id)->toBe($adFlow->id);
});

it('falls back to default active flow when no ad mapping exists', function () {
    $defaultFlow = MessengerBotFlow::factory()->active()->create(['name' => 'Default Flow']);
    MessengerBotStep::factory()->textQuestion()->create([
        'flow_id' => $defaultFlow->id,
        'sort_order' => 1,
        'message_text' => 'Default question?',
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'psid_organic'],
            'message' => [
                'mid' => 'mid.def',
                'text' => 'Hello',
            ],
        ]],
    ]);

    $conversation = MessengerConversation::where('facebook_psid', 'psid_organic')->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->flow_id)->toBe($defaultFlow->id);
});

it('falls back to default when referral ad_id has no mapping', function () {
    $defaultFlow = MessengerBotFlow::factory()->active()->create(['name' => 'Default Flow']);
    MessengerBotStep::factory()->textQuestion()->create([
        'flow_id' => $defaultFlow->id,
        'sort_order' => 1,
        'message_text' => 'Default question?',
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'psid_unknown_ad'],
            'message' => [
                'mid' => 'mid.ghi',
                'text' => 'Hi from ad',
            ],
            'referral' => [
                'source' => 'ADS',
                'ad_id' => 'unmapped_ad_id',
            ],
        ]],
    ]);

    $conversation = MessengerConversation::where('facebook_psid', 'psid_unknown_ad')->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->flow_id)->toBe($defaultFlow->id);
});

it('routes postback with referral to mapped flow', function () {
    $adFlow = MessengerBotFlow::factory()->create(['name' => 'Ad Flow', 'status' => 'draft']);
    MessengerBotStep::factory()->textQuestion()->create([
        'flow_id' => $adFlow->id,
        'sort_order' => 1,
        'message_text' => 'Ad question?',
    ]);

    // Also need a default active flow so the system has something to fall back on
    MessengerBotFlow::factory()->active()->create(['name' => 'Default']);

    MessengerAdFlowMapping::factory()->create([
        'facebook_ad_id' => '777666',
        'flow_id' => $adFlow->id,
    ]);

    $service = app(FacebookMessengerService::class);
    $service->processIncomingMessage([
        'messaging' => [[
            'sender' => ['id' => 'psid_postback'],
            'postback' => [
                'title' => 'Get Started',
                'payload' => 'GET_STARTED',
            ],
            'referral' => [
                'source' => 'ADS',
                'ad_id' => '777666',
            ],
        ]],
    ]);

    $conversation = MessengerConversation::where('facebook_psid', 'psid_postback')->first();
    expect($conversation)->not->toBeNull();
    expect($conversation->flow_id)->toBe($adFlow->id);
});
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --compact tests/Feature/Messenger/AdFlowRoutingTest.php
```

Expected: FAIL — referral data not processed

**Step 3: Modify `FacebookMessengerService::processIncomingMessage`**

In `app/Services/FacebookMessengerService.php`, change `processIncomingMessage()` to extract referral data and pass it through:

```php
public function processIncomingMessage(array $webhookEntry): void
{
    $messaging = $webhookEntry['messaging'] ?? [];

    foreach ($messaging as $event) {
        $psid = $event['sender']['id'] ?? null;

        if (! $psid) {
            continue;
        }

        // Extract referral data (present when message originates from an ad)
        $referral = $event['referral'] ?? $event['message']['referral'] ?? $event['postback']['referral'] ?? null;

        if (isset($event['message'])) {
            $this->handleMessage($psid, $event['message'], $referral);
        } elseif (isset($event['postback'])) {
            $this->processPostback($psid, $event['postback'], $referral);
        }
    }
}
```

**Step 4: Modify `handleMessage` signature and add flow resolution**

Change `handleMessage` to accept and pass referral:

```php
protected function handleMessage(string $psid, array $message, ?array $referral = null): void
{
    $text = $message['text'] ?? '';
    $quickReplyPayload = $message['quick_reply']['payload'] ?? null;

    if ($quickReplyPayload) {
        $this->processPostback($psid, ['payload' => $quickReplyPayload], $referral);
        return;
    }

    $thread = $this->findOrCreateThread($psid);

    ChatMessage::create([
        'thread_id' => $thread->id,
        'user_id' => null,
        'content' => $text,
        'type' => ChatMessage::TYPE_TEXT,
        'metadata' => [
            'source' => 'facebook_messenger',
            'psid' => $psid,
            'mid' => $message['mid'] ?? null,
        ],
    ]);

    $thread->update(['last_message_at' => now()]);

    $conversation = MessengerConversation::where('facebook_psid', $psid)
        ->inProgress()
        ->first();

    if ($conversation) {
        app(MessengerBotFlowService::class)->processResponse($conversation, $text);
        return;
    }

    // Resolve flow from ad mapping or fall back to default
    $flow = $this->resolveFlowFromReferral($referral);
    app(MessengerBotFlowService::class)->startConversation($thread, $psid, $flow);
}
```

**Step 5: Modify `processPostback` similarly**

```php
public function processPostback(string $psid, array $postback, ?array $referral = null): void
{
    $payload = $postback['payload'] ?? '';

    $thread = $this->findOrCreateThread($psid);

    ChatMessage::create([
        'thread_id' => $thread->id,
        'user_id' => null,
        'content' => $postback['title'] ?? $payload,
        'type' => ChatMessage::TYPE_TEXT,
        'metadata' => [
            'source' => 'facebook_messenger',
            'psid' => $psid,
            'postback_payload' => $payload,
        ],
    ]);

    $thread->update(['last_message_at' => now()]);

    $conversation = MessengerConversation::where('facebook_psid', $psid)
        ->inProgress()
        ->first();

    if ($conversation) {
        app(MessengerBotFlowService::class)->processResponse($conversation, $postback['title'] ?? $payload);
        return;
    }

    $flow = $this->resolveFlowFromReferral($referral);
    app(MessengerBotFlowService::class)->startConversation($thread, $psid, $flow);
}
```

**Step 6: Add `resolveFlowFromReferral` method to `FacebookMessengerService`**

```php
/**
 * Resolve a bot flow from ad referral data.
 * Returns the mapped flow if an ad_id mapping exists, null otherwise.
 */
protected function resolveFlowFromReferral(?array $referral): ?MessengerBotFlow
{
    if (! $referral || empty($referral['ad_id'])) {
        return null;
    }

    $mapping = MessengerAdFlowMapping::where('facebook_ad_id', $referral['ad_id'])->first();

    if (! $mapping) {
        return null;
    }

    return $mapping->flow;
}
```

Add the import at the top of `FacebookMessengerService.php`:

```php
use App\Models\MessengerAdFlowMapping;
use App\Models\MessengerBotFlow;
```

**Step 7: Modify `MessengerBotFlowService::startConversation` to accept optional flow**

In `app/Services/MessengerBotFlowService.php`, change `startConversation()`:

```php
public function startConversation(ChatThread $thread, string $psid, ?MessengerBotFlow $flowOverride = null): ?MessengerConversation
{
    $flow = $flowOverride ?? $this->getActiveFlow();
    if (! $flow) {
        return null;
    }

    // ... rest of method unchanged
```

**Step 8: Run tests**

```bash
php artisan test --compact tests/Feature/Messenger/AdFlowRoutingTest.php
```

Expected: PASS

**Step 9: Run existing messenger tests to check for regressions**

```bash
php artisan test --compact tests/Feature/Messenger/
```

Expected: All pass

**Step 10: Commit**

```bash
git add app/Services/FacebookMessengerService.php app/Services/MessengerBotFlowService.php tests/Feature/Messenger/AdFlowRoutingTest.php
git commit -m "feat: route messages to bot flows based on ad referral data"
```

---

### Task 5: Ad Routing UI on Messenger Settings Page

**Files:**
- Modify: `resources/views/pages/settings/⚡messenger-settings.blade.php` (add PHP methods + template section)

**Step 1: Add PHP properties and methods to the component**

Add these properties after line 38 (`public array $flows = [];`):

```php
// Ad Routing
public string $adAccountId = '';
public array $adMappings = [];
public bool $syncing = false;
```

In `mount()`, after `$this->widgetThemeColor` line, add:

```php
$this->adAccountId = $settings->ad_account_id ?? '';
$this->loadAdMappings();
```

Add new methods after `loadFlows()`:

```php
public function loadAdMappings(): void
{
    $this->adMappings = \App\Models\MessengerAdFlowMapping::query()
        ->orderBy('facebook_campaign_name')
        ->orderByRaw("CASE WHEN facebook_ad_status = 'ACTIVE' THEN 0 ELSE 1 END")
        ->orderBy('facebook_ad_name')
        ->get()
        ->toArray();
}

public function saveAdAccountId(): void
{
    if (Auth::user()->type !== 'internal') {
        abort(403);
    }

    $this->validate([
        'adAccountId' => ['nullable', 'string', 'max:255'],
    ]);

    $settings = MessengerSetting::getInstance();
    $settings->update([
        'ad_account_id' => $this->adAccountId ?: null,
    ]);

    $this->dispatch('messenger-settings-saved');
}

public function syncAds(): void
{
    if (Auth::user()->type !== 'internal') {
        abort(403);
    }

    $service = app(\App\Services\FacebookMarketingService::class);
    $count = $service->syncAds();

    $this->loadAdMappings();
    $this->dispatch('messenger-settings-saved');
    session()->flash('ad-sync-message', __(':count ads synced from Facebook.', ['count' => $count]));
}

public function updateAdFlowMapping(int $mappingId, string $flowId): void
{
    if (Auth::user()->type !== 'internal') {
        abort(403);
    }

    $mapping = \App\Models\MessengerAdFlowMapping::findOrFail($mappingId);

    if ($flowId === '') {
        // "Default" selected — remove the flow assignment
        $mapping->update(['flow_id' => null]);
    } else {
        $mapping->update(['flow_id' => (int) $flowId]);
    }

    $this->loadAdMappings();
}
```

**Important:** The `flow_id` column has a NOT NULL foreign key constraint. For "Default" (no specific flow), we need to handle this. There are two approaches:
- Allow `flow_id` to be nullable (requires migration change in Task 2 — change `->constrained()` to `->nullable()->constrained()`)
- OR delete the mapping row when "Default" is chosen

**Recommended: Make `flow_id` nullable.** Update the Task 2 migration to use:
```php
$table->foreignId('flow_id')->nullable()->constrained('messenger_bot_flows')->nullOnDelete();
```

And update `updateAdFlowMapping` to set `flow_id` to null for "Default".

**Step 2: Add template section**

After the Bot Flows section closing `</div>` (before `</x-pages::settings.layout>`), add:

```blade
<flux:separator />

{{-- Ad Routing --}}
<div class="my-6 w-full space-y-6">
    <div>
        <flux:heading size="sm">{{ __('Ad Routing') }}</flux:heading>
        <flux:text class="text-sm text-zinc-500">
            {{ __('Assign different bot flows to different Facebook ads. Ads without an assignment use the default active flow.') }}
        </flux:text>
    </div>

    {{-- Ad Account ID --}}
    <div class="flex items-end gap-3">
        <div class="flex-1">
            <flux:input wire:model="adAccountId" :label="__('Ad Account ID')" placeholder="act_123456789"
                :description="__('Found in Facebook Ads Manager under Account Settings.')" />
        </div>
        <flux:button wire:click="saveAdAccountId" variant="primary">{{ __('Save') }}</flux:button>
        @if ($adAccountId)
            <flux:button wire:click="syncAds" wire:loading.attr="disabled" wire:target="syncAds">
                <span wire:loading.remove wire:target="syncAds">{{ __('Sync Ads') }}</span>
                <span wire:loading wire:target="syncAds">{{ __('Syncing...') }}</span>
            </flux:button>
        @endif
    </div>

    @if (session('ad-sync-message'))
        <flux:callout variant="success">{{ session('ad-sync-message') }}</flux:callout>
    @endif

    <x-action-message class="me-3" on="messenger-settings-saved">
        {{ __('Saved.') }}
    </x-action-message>

    {{-- Ad list grouped by campaign --}}
    @if (count($adMappings) > 0)
        @php
            $grouped = collect($adMappings)->groupBy('facebook_campaign_name');
        @endphp

        <div class="space-y-4">
            @foreach ($grouped as $campaignName => $ads)
                <div class="rounded-lg border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                    <div class="bg-zinc-50 dark:bg-zinc-800 px-4 py-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        {{ $campaignName ?: __('Unknown Campaign') }}
                    </div>
                    <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($ads as $mapping)
                            <div wire:key="ad-{{ $mapping['id'] }}" class="flex items-center justify-between px-4 py-3">
                                <div class="flex items-center gap-3 min-w-0 flex-1">
                                    <flux:badge size="sm" :color="$mapping['facebook_ad_status'] === 'ACTIVE' ? 'green' : 'zinc'">
                                        {{ $mapping['facebook_ad_status'] ?? 'UNKNOWN' }}
                                    </flux:badge>
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300 truncate">
                                            {{ $mapping['facebook_ad_name'] ?? $mapping['facebook_ad_id'] }}
                                        </div>
                                        @if ($mapping['facebook_adset_name'])
                                            <div class="text-xs text-zinc-400 truncate">{{ $mapping['facebook_adset_name'] }}</div>
                                        @endif
                                    </div>
                                </div>
                                <div class="w-52 shrink-0">
                                    <flux:select
                                        size="sm"
                                        wire:change="updateAdFlowMapping({{ $mapping['id'] }}, $event.target.value)"
                                    >
                                        <flux:select.option value="" :selected="empty($mapping['flow_id'])">{{ __('Default (active flow)') }}</flux:select.option>
                                        @foreach ($flows as $flow)
                                            <flux:select.option value="{{ $flow['id'] }}" :selected="($mapping['flow_id'] ?? null) == $flow['id']">
                                                {{ $flow['name'] }}
                                            </flux:select.option>
                                        @endforeach
                                    </flux:select>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        @if (($lastSync = collect($adMappings)->max('last_synced_at')))
            <flux:text size="xs" class="text-zinc-400">
                {{ __('Last synced: :time', ['time' => \Carbon\Carbon::parse($lastSync)->diffForHumans()]) }}
            </flux:text>
        @endif
    @elseif ($adAccountId)
        <div class="rounded-lg border border-dashed border-zinc-300 py-8 text-center dark:border-zinc-600">
            <flux:text class="text-sm text-zinc-500">{{ __('No ads synced yet. Click "Sync Ads" to pull your ads from Facebook.') }}</flux:text>
        </div>
    @endif
</div>
```

**Step 3: Commit**

```bash
git add resources/views/pages/settings/⚡messenger-settings.blade.php
git commit -m "feat: add Ad Routing UI to messenger settings"
```

---

### Task 6: Tests for Ad Routing UI

**Files:**
- Create: `tests/Feature/Messenger/AdRoutingSettingsTest.php`

**Step 1: Write UI tests**

```php
<?php

use App\Models\MessengerAdFlowMapping;
use App\Models\MessengerBotFlow;
use App\Models\MessengerSetting;
use App\Models\User;
use App\Services\FacebookMarketingService;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);

    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    $settings->update([
        'verify_token' => 'test-token',
        'page_access_token' => 'test-page-token',
        'ad_account_id' => 'act_123456',
    ]);
    MessengerSetting::clearInstanceCache();
});

afterEach(function () {
    MessengerSetting::clearInstanceCache();
});

it('displays ad account id field', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    Livewire::actingAs($user)
        ->test('pages::settings.messenger-settings')
        ->assertSet('adAccountId', 'act_123456')
        ->assertSee('Ad Routing');
});

it('saves ad account id', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    Livewire::actingAs($user)
        ->test('pages::settings.messenger-settings')
        ->set('adAccountId', 'act_999888')
        ->call('saveAdAccountId')
        ->assertDispatched('messenger-settings-saved');

    MessengerSetting::clearInstanceCache();
    expect(MessengerSetting::getInstance()->ad_account_id)->toBe('act_999888');
});

it('syncs ads from the Marketing API', function () {
    Http::fake([
        'graph.facebook.com/v19.0/act_123456/ads*' => Http::response([
            'data' => [
                [
                    'id' => '111',
                    'name' => 'Test Ad',
                    'status' => 'ACTIVE',
                    'campaign' => ['id' => 'c1', 'name' => 'Test Campaign'],
                    'adset' => ['id' => 'as1', 'name' => 'Test AdSet'],
                ],
            ],
        ]),
    ]);

    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    Livewire::actingAs($user)
        ->test('pages::settings.messenger-settings')
        ->call('syncAds');

    expect(MessengerAdFlowMapping::count())->toBe(1);
    expect(MessengerAdFlowMapping::first()->facebook_ad_name)->toBe('Test Ad');
});

it('can assign a flow to an ad', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $flow = MessengerBotFlow::factory()->create(['name' => 'Interior Flow']);
    $mapping = MessengerAdFlowMapping::factory()->create([
        'facebook_ad_id' => '555',
        'flow_id' => null,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.messenger-settings')
        ->call('updateAdFlowMapping', $mapping->id, (string) $flow->id);

    expect($mapping->fresh()->flow_id)->toBe($flow->id);
});

it('can unassign a flow from an ad (set to default)', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('messenger.manage');

    $flow = MessengerBotFlow::factory()->create();
    $mapping = MessengerAdFlowMapping::factory()->create([
        'facebook_ad_id' => '666',
        'flow_id' => $flow->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::settings.messenger-settings')
        ->call('updateAdFlowMapping', $mapping->id, '');

    expect($mapping->fresh()->flow_id)->toBeNull();
});
```

**Step 2: Run tests**

```bash
php artisan test --compact tests/Feature/Messenger/AdRoutingSettingsTest.php
```

Expected: PASS

**Step 3: Commit**

```bash
git add tests/Feature/Messenger/AdRoutingSettingsTest.php
git commit -m "test: add Ad Routing UI tests"
```

---

### Task 7: Run Full Messenger Test Suite + Pint

**Step 1: Run pint**

```bash
vendor/bin/pint --dirty
```

**Step 2: Run all messenger tests**

```bash
php artisan test --compact tests/Feature/Messenger/
```

Expected: All pass

**Step 3: Final commit if pint made changes**

```bash
git add -A
git commit -m "style: pint formatting"
```

---

## File Summary

| File | Action |
|------|--------|
| `database/migrations/..._add_ad_account_id_to_messenger_settings_table.php` | Create |
| `database/migrations/..._create_messenger_ad_flow_mappings_table.php` | Create |
| `app/Models/MessengerSetting.php` | Modify (add `ad_account_id` to fillable) |
| `app/Models/MessengerAdFlowMapping.php` | Create |
| `database/factories/MessengerAdFlowMappingFactory.php` | Create |
| `app/Services/FacebookMarketingService.php` | Create |
| `app/Services/FacebookMessengerService.php` | Modify (extract referral, resolve flow) |
| `app/Services/MessengerBotFlowService.php` | Modify (accept flow override) |
| `resources/views/pages/settings/⚡messenger-settings.blade.php` | Modify (add Ad Routing section) |
| `tests/Feature/Messenger/FacebookMarketingServiceTest.php` | Create |
| `tests/Feature/Messenger/AdFlowRoutingTest.php` | Create |
| `tests/Feature/Messenger/AdRoutingSettingsTest.php` | Create |

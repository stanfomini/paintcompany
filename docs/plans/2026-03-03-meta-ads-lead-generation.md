# Meta Ads + Lead Generation Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix Messenger bot bugs, update landing page with booking CTAs + join the team section, fix SEO sitemap, add Meta Pixel, and build Messenger bot flows — preparing the system for a $1,500 Meta ad campaign targeting Front Range homeowners.

**Architecture:** Three workstreams: (1) Fix 3 bugs in the existing Messenger bot system so conversations complete end-to-end, (2) Update the landing page hero + add sections without breaking existing layout, (3) SEO/tracking infrastructure (sitemap, Meta Pixel). All changes are additive to existing code.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Facebook Graph API v19.0, Meta Pixel JS, Pest tests

**Design doc:** `docs/plans/2026-03-03-meta-ads-lead-generation-design.md`

---

## Task 1: Fix Messenger Bot Response Routing

The biggest bug — when a user replies to a bot conversation, the reply is stored as a ChatMessage but the bot never advances. The `handleMessage()` method returns early without calling `processResponse()`.

**Files:**
- Modify: `app/Services/FacebookMessengerService.php:205-208`
- Test: `tests/Feature/Messenger/MessengerBotFlowServiceTest.php`

**Step 1: Write the failing test**

Add to `tests/Feature/Messenger/MessengerBotFlowServiceTest.php` at the end of the file:

```php
describe('end-to-end bot conversation via webhook', function () {
    it('advances conversation when user replies to bot step', function () {
        $flow = MessengerBotFlow::factory()->active()->create([
            'greeting_message' => 'Welcome!',
        ]);

        $step1 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'What is your name?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion('customer.email')->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'What is your email?',
        ]);

        $thread = ChatThread::factory()->facebook('99998888')->create();
        $service = app(MessengerBotFlowService::class);

        // Start conversation
        $conversation = $service->startConversation($thread, '99998888');
        expect($conversation->current_step_id)->toBe($step1->id);

        // Simulate user replying to step 1
        $service->processResponse($conversation->fresh(), 'John');

        $conversation->refresh();
        expect($conversation->current_step_id)->toBe($step2->id);
        expect($conversation->answers)->toHaveKey("step_{$step1->id}", 'John');
    });

    it('routes incoming message to bot flow service when conversation is active', function () {
        $flow = MessengerBotFlow::factory()->active()->create([
            'greeting_message' => 'Welcome!',
        ]);

        $step1 = MessengerBotStep::factory()->textQuestion('customer.first_name')->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'What is your name?',
        ]);

        $step2 = MessengerBotStep::factory()->textQuestion('customer.email')->create([
            'flow_id' => $flow->id,
            'sort_order' => 2,
            'message_text' => 'What is your email?',
        ]);

        $thread = ChatThread::factory()->facebook('77776666')->create();

        // Create an active conversation manually (simulating one already started)
        $conversation = MessengerConversation::create([
            'flow_id' => $flow->id,
            'thread_id' => $thread->id,
            'facebook_psid' => '77776666',
            'status' => 'in_progress',
            'current_step_id' => $step1->id,
            'answers' => [],
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);

        // Simulate an incoming message via the service (this is what the webhook calls)
        $messengerService = app(\App\Services\FacebookMessengerService::class);
        $messengerService->processIncomingMessage([
            'messaging' => [[
                'sender' => ['id' => '77776666'],
                'message' => ['text' => 'Jane'],
            ]],
        ]);

        $conversation->refresh();
        expect($conversation->current_step_id)->toBe($step2->id);
        expect($conversation->answers)->toHaveKey("step_{$step1->id}", 'Jane');
    });
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="routes incoming message to bot flow service"`
Expected: FAIL — conversation does not advance because of the early `return` at line 207.

**Step 3: Implement the fix**

In `app/Services/FacebookMessengerService.php`, replace lines 205-208:

```php
// BEFORE (broken):
if ($conversation) {
    // Route to bot flow (will be handled by MessengerBotFlowService)
    return;
}

// AFTER (fixed):
if ($conversation) {
    app(MessengerBotFlowService::class)->processResponse($conversation, $text);

    return;
}
```

Also add the import at the top of the file if not already present:
```php
use App\Services\MessengerBotFlowService;
```

Note: The `$text` variable is already extracted earlier in `handleMessage()` — verify it's available at this point. It should be from `$message['text'] ?? ''` around line 175.

**Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter="routes incoming message to bot flow service"`
Expected: PASS

Run: `php artisan test --compact tests/Feature/Messenger/MessengerBotFlowServiceTest.php`
Expected: All tests pass

**Step 5: Commit**

```bash
git add app/Services/FacebookMessengerService.php tests/Feature/Messenger/MessengerBotFlowServiceTest.php
git commit -m "fix: wire bot response routing so conversations advance past step 1"
```

---

## Task 2: Fix Quick Reply Field Name Mismatch

The bot flow editor stores quick reply options as `{label, value}` but `sendQuickReply()` expects `{title, payload}`. Fix this in the service layer (translate at send time) so existing saved flows work without migration.

**Files:**
- Modify: `app/Services/MessengerBotFlowService.php:113-120`
- Test: `tests/Feature/Messenger/MessengerBotFlowServiceTest.php`

**Step 1: Write the failing test**

Add to `tests/Feature/Messenger/MessengerBotFlowServiceTest.php`:

```php
describe('quick reply options', function () {
    it('translates label/value to title/payload when sending quick replies', function () {
        $flow = MessengerBotFlow::factory()->active()->create([
            'greeting_message' => 'Hi there!',
        ]);

        $step = MessengerBotStep::factory()->quickReply()->create([
            'flow_id' => $flow->id,
            'sort_order' => 1,
            'message_text' => 'Interior or exterior?',
            'quick_reply_options' => [
                ['label' => 'Interior', 'value' => 'interior'],
                ['label' => 'Exterior', 'value' => 'exterior'],
            ],
        ]);

        $thread = ChatThread::factory()->facebook('55554444')->create();
        $service = app(MessengerBotFlowService::class);

        $conversation = $service->startConversation($thread, '55554444');

        // Verify the quick reply was sent with correct title/payload format
        Http::assertSent(function ($request) {
            $quickReplies = $request['message']['quick_replies'] ?? [];
            if (empty($quickReplies)) {
                return false;
            }

            return $quickReplies[0]['title'] === 'Interior'
                && $quickReplies[0]['payload'] === 'interior'
                && $quickReplies[1]['title'] === 'Exterior'
                && $quickReplies[1]['payload'] === 'exterior';
        });
    });
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="translates label/value to title/payload"`
Expected: FAIL — the options are sent with `label`/`value` keys which Facebook API ignores.

**Step 3: Implement the fix**

In `app/Services/MessengerBotFlowService.php`, replace the quick reply case (lines 113-120):

```php
case MessengerBotStep::TYPE_QUICK_REPLY:
    $options = collect($nextStep->quick_reply_options ?? [])
        ->map(fn (array $opt) => [
            'title' => $opt['title'] ?? $opt['label'] ?? '',
            'payload' => $opt['payload'] ?? $opt['value'] ?? '',
        ])
        ->filter(fn (array $opt) => $opt['title'] !== '' && $opt['payload'] !== '')
        ->values()
        ->all();

    if (! empty($options)) {
        $this->messengerService->sendQuickReply($psid, $nextStep->message_text, $options);
    } else {
        $this->messengerService->sendTextMessage($psid, $nextStep->message_text);
    }
    break;
```

This accepts both `{title, payload}` (API format) and `{label, value}` (editor format) — backwards compatible.

**Step 4: Run tests**

Run: `php artisan test --compact --filter="translates label/value to title/payload"`
Expected: PASS

Run: `php artisan test --compact tests/Feature/Messenger/MessengerBotFlowServiceTest.php`
Expected: All pass

**Step 5: Commit**

```bash
git add app/Services/MessengerBotFlowService.php tests/Feature/Messenger/MessengerBotFlowServiceTest.php
git commit -m "fix: translate quick reply label/value to title/payload for Facebook API"
```

---

## Task 3: Set `is_connected` After Webhook Verification

The settings page shows connection status based on `is_connected` but nothing ever sets it to `true`.

**Files:**
- Modify: `app/Http/Controllers/FacebookWebhookController.php:26-28`
- Test: `tests/Feature/Messenger/FacebookWebhookTest.php`

**Step 1: Write the failing test**

Add to `tests/Feature/Messenger/FacebookWebhookTest.php`:

```php
it('sets is_connected to true after successful webhook verification', function () {
    $settings = MessengerSetting::getInstance();
    expect($settings->is_connected)->toBeFalse();

    $this->get('/webhook/facebook/receive?' . http_build_query([
        'hub_mode' => 'subscribe',
        'hub_verify_token' => $settings->verify_token,
        'hub_challenge' => 'test-challenge-123',
    ]))->assertOk();

    MessengerSetting::clearInstanceCache();
    $settings = MessengerSetting::getInstance();
    expect($settings->is_connected)->toBeTrue();
    expect($settings->connected_at)->not->toBeNull();
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="sets is_connected to true"`
Expected: FAIL — `is_connected` remains false.

**Step 3: Implement the fix**

In `app/Http/Controllers/FacebookWebhookController.php`, replace lines 26-28:

```php
if ($result !== null) {
    MessengerSetting::getInstance()->update([
        'is_connected' => true,
        'connected_at' => now(),
    ]);

    return response($result, 200);
}
```

Add the import at the top:
```php
use App\Models\MessengerSetting;
```

**Step 4: Run tests**

Run: `php artisan test --compact --filter="sets is_connected to true"`
Expected: PASS

Run: `php artisan test --compact tests/Feature/Messenger/FacebookWebhookTest.php`
Expected: All pass

**Step 5: Commit**

```bash
git add app/Http/Controllers/FacebookWebhookController.php tests/Feature/Messenger/FacebookWebhookTest.php
git commit -m "fix: set is_connected after successful Facebook webhook verification"
```

---

## Task 4: Update Landing Page Hero Section

Replace the single "Get Your Free Estimate in 24 Hours" button with a more powerful hero that includes service area coverage and dual CTAs (book appointment + tell us about your project).

**Files:**
- Modify: `resources/views/components/public/⚡landing-page.blade.php:447-484`
- No new test needed (visual/UI change to existing template)

**Step 1: Replace the hero section**

In `resources/views/components/public/⚡landing-page.blade.php`, replace the hero section content (lines 448-484). Keep the `{{-- Section 1: Hero --}}` comment and the section after it (Section 2). Replace the inner content of Section 1:

```blade
{{-- Section 1: Hero --}}
<section class="relative bg-gradient-to-br from-[#1B2D4F] via-[#1e3a6e] to-[#2563EB] text-white overflow-hidden pt-12">
    <div class="absolute inset-0 opacity-10">
        <div class="absolute top-0 right-0 w-96 h-96 bg-white rounded-full -translate-y-1/2 translate-x-1/3"></div>
        <div class="absolute bottom-0 left-0 w-72 h-72 bg-white rounded-full translate-y-1/2 -translate-x-1/3"></div>
    </div>
    <div class="relative max-w-5xl mx-auto px-4 sm:px-6 pt-16 sm:pt-24 pb-10 sm:pb-14 text-center">
        <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold tracking-tight mb-4 leading-tight">{!! __('Professional Painting, <span class="text-emerald-400">Fort Collins to Castle Rock</span>') !!}</h1>
        <p class="text-lg sm:text-xl text-blue-100 mb-4 max-w-3xl mx-auto leading-relaxed">{{ __('Denver\'s stress-free painting experience. Real-time project tracking, daily photo updates, and our "Cleaner Than We Found It" guarantee. No ghosting. No surprises. No mess.') }}</p>
        <p class="text-sm text-blue-200/80 mb-8 max-w-2xl mx-auto">{{ __('Serving the entire Front Range: Fort Collins, Loveland, Longmont, Boulder, Broomfield, Westminster, Arvada, Lakewood, Denver, Littleton, Centennial, Castle Rock & more') }}</p>
        <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
            <a href="{{ route('public.vendor-booking') }}" class="inline-flex items-center gap-3 bg-emerald-500 hover:bg-emerald-600 text-white font-bold px-8 py-4 rounded-xl text-lg transition-colors shadow-xl">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
                {{ __('Book Your Free Estimate') }}
            </a>
            <a href="#get-estimate" class="inline-flex items-center gap-3 bg-white/10 hover:bg-white/20 text-white font-semibold px-8 py-4 rounded-xl text-lg transition-colors border border-white/20">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
                {{ __('Tell Us About Your Project') }}
            </a>
        </div>
    </div>
    {{-- Trust Bar --}}
    <div class="relative border-t border-white/10">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 py-5">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center text-sm text-blue-100">
                <div class="flex flex-col items-center gap-1.5">
                    <svg class="w-5 h-5 text-amber-400" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/></svg>
                    <span class="font-medium">{{ __('5-Star Rated') }}</span>
                </div>
                <div class="flex flex-col items-center gap-1.5">
                    <svg class="w-5 h-5 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" /></svg>
                    <span class="font-medium">{{ __('Licensed & Insured') }}</span>
                </div>
                <div class="flex flex-col items-center gap-1.5">
                    <svg class="w-5 h-5 text-sky-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                    <span class="font-medium">{{ __('Same-Day Estimates') }}</span>
                </div>
                <div class="flex flex-col items-center gap-1.5">
                    <svg class="w-5 h-5 text-rose-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z" /></svg>
                    <span class="font-medium">{{ __('100% Satisfaction') }}</span>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- Booking CTA Bar --}}
<section class="bg-gradient-to-r from-emerald-600 to-emerald-500 py-4">
    <div class="max-w-4xl mx-auto px-6 flex flex-col sm:flex-row items-center justify-center gap-3 sm:gap-6">
        <p class="text-white font-medium text-base sm:text-lg text-center sm:text-left">{{ __('Skip the wait \u2014 book your free in-home estimate online') }}</p>
        <a href="{{ route('public.vendor-booking') }}" class="inline-flex items-center gap-2 bg-white text-emerald-700 font-bold px-5 py-2.5 rounded-lg text-sm transition-colors hover:bg-emerald-50 shrink-0">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" /></svg>
            {{ __('Book a Time') }}
        </a>
    </div>
</section>
```

**Step 2: Verify the route name**

Check that `route('public.vendor-booking')` is the correct named route for `/book`. Look in `routes/web.php` for the booking route name. If it's different, adjust the `route()` call accordingly.

**Step 3: Run Pint**

Run: `vendor/bin/pint --dirty`

**Step 4: Verify visually**

Run: `npm run build` (if needed) then check the landing page in browser.

**Step 5: Commit**

```bash
git add "resources/views/components/public/⚡landing-page.blade.php"
git commit -m "feat: update hero with Front Range coverage, dual CTAs, and booking bar"
```

---

## Task 5: Add "Join the Team" Section Above Footer

**Files:**
- Modify: `resources/views/components/public/⚡landing-page.blade.php:1380-1381`

**Step 1: Insert the section**

In `resources/views/components/public/⚡landing-page.blade.php`, insert the following after line 1379 (`<livewire:public.local-team />`) and before the `{{-- Section 14: Footer --}}` comment:

```blade

{{-- Section: Join the Team --}}
<section class="bg-zinc-900 py-12 sm:py-16">
    <div class="max-w-4xl mx-auto px-6 text-center">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-emerald-500/20 text-emerald-400 mb-4">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" /></svg>
        </div>
        <h2 class="text-2xl sm:text-3xl font-bold text-white mb-3">{{ __('We\'re Hiring Painters') }}</h2>
        <p class="text-zinc-400 text-lg mb-6 max-w-xl mx-auto">{{ __('Join a team that respects your craft and your time. Competitive pay, consistent work, and a company that invests in your growth.') }}</p>
        <a href="{{ route('careers') }}" class="inline-flex items-center gap-2 bg-emerald-500 hover:bg-emerald-600 text-white font-bold px-6 py-3 rounded-xl text-base transition-colors">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0M12 12.75h.008v.008H12v-.008z" /></svg>
            {{ __('View Open Positions') }}
        </a>
    </div>
</section>
```

**Step 2: Verify the route name**

Check `routes/web.php` for the careers route name. It should be `careers` based on the sitemap. Adjust if needed.

**Step 3: Commit**

```bash
git add "resources/views/components/public/⚡landing-page.blade.php"
git commit -m "feat: add Join the Team hiring section above landing page footer"
```

---

## Task 6: Fix Sitemap to Include Area Pages

Create an artisan command that generates the sitemap dynamically from the database, replacing the static file.

**Files:**
- Create: `app/Console/Commands/GenerateSitemapCommand.php`
- Modify: `public/sitemap.xml` (will be overwritten by command)
- Test: `tests/Feature/SitemapGenerationTest.php`

**Step 1: Write the failing test**

Create `tests/Feature/SitemapGenerationTest.php`:

```php
<?php

use App\Models\ServiceArea;

it('generates sitemap with area pages', function () {
    ServiceArea::factory()->create(['city' => 'Denver', 'slug' => 'denver', 'is_active' => true]);
    ServiceArea::factory()->create(['city' => 'Boulder', 'slug' => 'boulder', 'is_active' => true]);
    ServiceArea::factory()->create(['city' => 'Inactive City', 'slug' => 'inactive', 'is_active' => false]);

    $this->artisan('sitemap:generate')->assertExitCode(0);

    $sitemap = file_get_contents(public_path('sitemap.xml'));

    expect($sitemap)
        ->toContain('/areas/denver')
        ->toContain('/areas/boulder')
        ->not->toContain('/areas/inactive')
        ->toContain('/book')
        ->toContain('/careers');
});
```

**Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="generates sitemap with area pages"`
Expected: FAIL — command doesn't exist.

**Step 3: Create the command**

Run: `php artisan make:command GenerateSitemapCommand --no-interaction`

Then implement in `app/Console/Commands/GenerateSitemapCommand.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\ServiceArea;
use Illuminate\Console\Command;

class GenerateSitemapCommand extends Command
{
    protected $signature = 'sitemap:generate';

    protected $description = 'Generate sitemap.xml with all public pages and service areas';

    public function handle(): int
    {
        $baseUrl = config('app.url');

        $urls = collect();

        // Static pages
        $urls->push(['loc' => $baseUrl, 'changefreq' => 'weekly', 'priority' => '1.0']);
        $urls->push(['loc' => $baseUrl.'/book', 'changefreq' => 'weekly', 'priority' => '0.9']);
        $urls->push(['loc' => $baseUrl.'/areas', 'changefreq' => 'weekly', 'priority' => '0.8']);
        $urls->push(['loc' => $baseUrl.'/calculator', 'changefreq' => 'monthly', 'priority' => '0.8']);
        $urls->push(['loc' => $baseUrl.'/careers', 'changefreq' => 'monthly', 'priority' => '0.6']);
        $urls->push(['loc' => $baseUrl.'/watch', 'changefreq' => 'weekly', 'priority' => '0.5']);
        $urls->push(['loc' => $baseUrl.'/terms', 'changefreq' => 'yearly', 'priority' => '0.3']);
        $urls->push(['loc' => $baseUrl.'/privacy', 'changefreq' => 'yearly', 'priority' => '0.3']);
        $urls->push(['loc' => $baseUrl.'/referral-terms', 'changefreq' => 'yearly', 'priority' => '0.3']);

        // Dynamic service area pages
        ServiceArea::active()->orderBy('city')->each(function (ServiceArea $area) use ($urls, $baseUrl) {
            $urls->push([
                'loc' => $baseUrl.'/areas/'.$area->slug,
                'changefreq' => 'weekly',
                'priority' => '0.8',
            ]);
        });

        // Build XML
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url>';
            $xml .= '<loc>'.htmlspecialchars($url['loc']).'</loc>';
            $xml .= '<changefreq>'.$url['changefreq'].'</changefreq>';
            $xml .= '<priority>'.$url['priority'].'</priority>';
            $xml .= '</url>'."\n";
        }

        $xml .= '</urlset>'."\n";

        file_put_contents(public_path('sitemap.xml'), $xml);

        $this->info('Sitemap generated with '.$urls->count().' URLs.');

        return self::SUCCESS;
    }
}
```

**Step 4: Run test**

Run: `php artisan test --compact --filter="generates sitemap with area pages"`
Expected: PASS

**Step 5: Generate the sitemap for production**

Run: `php artisan sitemap:generate`

**Step 6: Commit**

```bash
git add app/Console/Commands/GenerateSitemapCommand.php tests/Feature/SitemapGenerationTest.php public/sitemap.xml
git commit -m "feat: dynamic sitemap generation with service area pages"
```

---

## Task 7: Add Meta Pixel Partial

Create a reusable partial for the Meta Pixel that can be included in layouts. The pixel ID will be stored in `config/services.php` and loaded from `.env`.

**Files:**
- Create: `resources/views/partials/meta-pixel.blade.php`
- Modify: `config/services.php` (add `meta` block)
- Modify: `resources/views/partials/head.blade.php` (include pixel partial)

**Step 1: Add config**

Add to `config/services.php` inside the return array:

```php
'meta' => [
    'pixel_id' => env('META_PIXEL_ID'),
],
```

**Step 2: Create the pixel partial**

Create `resources/views/partials/meta-pixel.blade.php`:

```blade
@if(config('services.meta.pixel_id'))
<!-- Meta Pixel Code -->
<script>
!function(f,b,e,v,n,t,s)
{if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};
if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];
s.parentNode.insertBefore(t,s)}(window, document,'script',
'https://connect.facebook.net/en_US/fbevents.js');
fbq('init', '{{ config('services.meta.pixel_id') }}');
fbq('track', 'PageView');
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={{ config('services.meta.pixel_id') }}&ev=PageView&noscript=1"/></noscript>
<!-- End Meta Pixel Code -->
@endif
```

**Step 3: Include in head partial**

In `resources/views/partials/head.blade.php`, add at the end (before the closing content):

```blade
@include('partials.meta-pixel')
```

**Step 4: Add the env variable**

Add to `.env.example` (not `.env` — that's user-managed):

```
META_PIXEL_ID=
```

**Step 5: Commit**

```bash
git add resources/views/partials/meta-pixel.blade.php config/services.php resources/views/partials/head.blade.php
git commit -m "feat: add Meta Pixel support with configurable pixel ID"
```

---

## Task 8: Add Meta Pixel Conversion Events

Fire `Lead` event when the landing page form is submitted and `Schedule` event when a booking is completed.

**Files:**
- Modify: `resources/views/components/public/⚡landing-page.blade.php` (add pixel event on form submit)
- Modify: `resources/views/components/public/⚡vendor-booking.blade.php` (add pixel event on booking)

**Step 1: Add Lead event to landing page form**

In `resources/views/components/public/⚡landing-page.blade.php`, find the `submitLeadForm` method in the PHP section. Add a `$dispatch('meta-pixel-lead')` call after the redirect. Alternatively, add JS to the form's `wire:submit` attribute area. The cleanest approach is a Livewire event + Alpine listener.

Add this script block at the bottom of the landing page template (before the closing `</div>`):

```blade
@script
<script>
    Livewire.on('lead-submitted', () => {
        if (typeof fbq === 'function') {
            fbq('track', 'Lead');
        }
    });
</script>
@endscript
```

Then in the PHP section, add a dispatch before the redirect in `submitLeadForm()`:

```php
$this->dispatch('lead-submitted');
```

And same in `submitScarcityLead()`:

```php
$this->dispatch('lead-submitted');
```

**Step 2: Add Schedule event to booking page**

In `resources/views/components/public/⚡vendor-booking.blade.php`, add the same pattern for booking completion. Find the method that completes a booking and add:

```php
$this->dispatch('booking-completed');
```

And in the template:

```blade
@script
<script>
    Livewire.on('booking-completed', () => {
        if (typeof fbq === 'function') {
            fbq('track', 'Schedule');
        }
    });
</script>
@endscript
```

**Step 3: Commit**

```bash
git add "resources/views/components/public/⚡landing-page.blade.php" "resources/views/components/public/⚡vendor-booking.blade.php"
git commit -m "feat: fire Meta Pixel Lead and Schedule events on form submit and booking"
```

---

## Task 9: Build Messenger Bot Flows via Bot Editor

This is a manual/configuration task using the existing bot flow editor UI, not a code change. But documenting the exact steps and flow content here.

**Prerequisites:**
- Tasks 1-3 must be complete (bot bugs fixed)
- Facebook Page must be connected (MessengerSetting configured)

**Step 1: Access the bot flow editor**

Navigate to `/settings/messenger` in the internal admin panel. Verify the connection status shows green.

**Step 2: Create Flow 1 — "Book or Be Booked"**

Click "Create New Flow" and configure:
- **Name:** Book or Be Booked
- **Greeting:** Hey! Thanks for reaching out about painting your home. I can help you get started right away.
- **Timeout:** 60 minutes

Steps (in order):
1. **Quick Reply** — "Would you like to book your own appointment, or should I have someone reach out to you?" / Options: `Book Myself` (value: `book_self`), `Book It For Me` (value: `book_for_me`)
2. **Text Question** — "Great! What's your first name?" / Field mapping: `customer.first_name`
3. **Text Question** — "What's your email address?" / Field mapping: `customer.email`
4. **Text Question** — "And your phone number?" / Field mapping: `customer.phone`
5. **Text Question** — "What city are you in?" / Field mapping: `customer.city`
6. **Quick Reply** — "Interior painting, exterior, or both?" / Options: `Interior` (value: `interior`), `Exterior` (value: `exterior`), `Both` (value: `both`) / Field mapping: `deal.description`
7. **Handoff** — "Thanks! We'll reach out within 2 hours to get you scheduled. Talk soon!"

Activate the flow.

**Step 3: Create Flow 2 — "Quick Quote" (save as draft)**

- **Name:** Quick Quote
- **Greeting:** Want a ballpark estimate in 60 seconds? Let's do it!
- **Steps:** Quick reply for scope → Quick reply for rooms → Quick reply for size → Text with price range → Quick reply for booking

Save as draft (only one flow can be active at a time — swap for A/B testing via Meta ads).

**Step 4: Create Flow 3 — "Spring Booking" (save as draft)**

- **Name:** Spring Booking
- **Greeting:** Spring painting season fills up fast — we're already booking March and April. Want to grab a spot?
- **Steps:** Quick reply for interest → Text questions for info → Handoff

Save as draft.

**Note:** To test different flows with different Meta ads, swap the active flow before launching each ad set, OR modify the system to support multiple active flows matched by ad campaign ID (future enhancement).

---

## Task 10: Run All Messenger Tests

Verify everything still works end-to-end after all changes.

**Step 1: Run full Messenger test suite**

Run: `php artisan test --compact tests/Feature/Messenger/`
Expected: All tests pass

**Step 2: Run sitemap test**

Run: `php artisan test --compact tests/Feature/SitemapGenerationTest.php`
Expected: PASS

**Step 3: Run Pint**

Run: `vendor/bin/pint --dirty`

**Step 4: Final commit if Pint made changes**

```bash
git add -A
git commit -m "style: format code with Pint"
```

---

## Summary of All Changes

| Task | Type | Files Changed |
|------|------|---------------|
| 1. Bot response routing | Bug fix | `FacebookMessengerService.php`, test |
| 2. Quick reply field mismatch | Bug fix | `MessengerBotFlowService.php`, test |
| 3. is_connected flag | Bug fix | `FacebookWebhookController.php`, test |
| 4. Hero section + booking bar | Feature | `⚡landing-page.blade.php` |
| 5. Join the Team section | Feature | `⚡landing-page.blade.php` |
| 6. Dynamic sitemap | Feature | New command + test + sitemap.xml |
| 7. Meta Pixel | Feature | New partial + config + head partial |
| 8. Pixel conversion events | Feature | Landing page + booking page |
| 9. Bot flows | Config | Via admin UI (no code) |
| 10. Verification | Testing | Run all tests |

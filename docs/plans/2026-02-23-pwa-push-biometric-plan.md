# PWA + Push Notifications + Biometric Login Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make the app installable as a PWA with native push notifications (replacing email noise) and biometric login (Face ID/fingerprint) for internal users, expandable to all user types.

**Architecture:** Service worker for caching/push/auto-update, Web Push API with VAPID keys via `minishlink/web-push`, WebAuthn/FIDO2 via `web-auth/webauthn-lib` for biometric auth. All gated behind `isInternal()` initially. User notification preferences stored as JSON on the users table.

**Tech Stack:** Laravel 12, Livewire 4 SFC, Flux UI v2, minishlink/web-push, web-auth/webauthn-lib, Web Push API, WebAuthn API, Service Workers

**Design Doc:** `docs/plans/2026-02-23-pwa-push-biometric-design.md`

---

## Phase 1: PWA Foundation (Manifest + Service Worker + Install Prompt)

### Task 1: Web App Manifest

**Files:**
- Create: `public/manifest.json`
- Modify: `resources/views/partials/head.blade.php`

**Step 1: Create manifest.json**

```json
{
    "name": "Apex CRM",
    "short_name": "Apex",
    "description": "Painting company CRM",
    "start_url": "/?source=pwa",
    "scope": "/",
    "display": "standalone",
    "orientation": "any",
    "theme_color": "#3b82f6",
    "background_color": "#18181b",
    "icons": [
        {
            "src": "/icons/icon-192.png",
            "sizes": "192x192",
            "type": "image/png",
            "purpose": "any"
        },
        {
            "src": "/icons/icon-512.png",
            "sizes": "512x512",
            "type": "image/png",
            "purpose": "any"
        },
        {
            "src": "/icons/icon-maskable-192.png",
            "sizes": "192x192",
            "type": "image/png",
            "purpose": "maskable"
        },
        {
            "src": "/icons/icon-maskable-512.png",
            "sizes": "512x512",
            "type": "image/png",
            "purpose": "maskable"
        }
    ]
}
```

**Step 2: Generate app icons**

Create icons directory and generate placeholder icons. Use the app's blue brand color (#3b82f6) with white "A" letter. Create `public/icons/` with 192px and 512px versions (regular + maskable).

For now, create simple SVG-based PNG placeholders. The user can replace with real branded icons later.

**Step 3: Add manifest link to head partial**

In `resources/views/partials/head.blade.php`, add after the existing favicon links:

```html
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#3b82f6">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Apex CRM">
<link rel="apple-touch-icon" href="/icons/icon-192.png">
```

**Step 4: Commit**

```bash
git add public/manifest.json public/icons/ resources/views/partials/head.blade.php
git commit -m "feat: add PWA web app manifest and icons"
```

---

### Task 2: Service Worker

**Files:**
- Create: `public/sw.js`
- Create: `public/offline.html`
- Create: `resources/js/pwa.js`
- Modify: `resources/js/app.js`

**Step 1: Create offline fallback page**

Create `public/offline.html` — a simple, self-contained HTML page (no external CSS/JS) styled to match the app. Shows: "You're offline — reconnect to use Apex CRM" with the app icon and a Retry button.

**Step 2: Create service worker**

Create `public/sw.js`:

```javascript
const CACHE_VERSION = 'dev';
const CACHE_NAME = `apex-${CACHE_VERSION}`;
const OFFLINE_URL = '/offline.html';

// Assets to pre-cache on install
const PRECACHE_URLS = [
    OFFLINE_URL,
];

// Install: pre-cache critical assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS))
    );
    self.skipWaiting();
});

// Activate: clean old caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(
                keys
                    .filter((key) => key.startsWith('apex-') && key !== CACHE_NAME)
                    .map((key) => caches.delete(key))
            )
        )
    );
    self.clients.claim();
});

// Fetch: network-first for navigations, cache-first for static assets
self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Skip non-GET and cross-origin
    if (request.method !== 'GET' || !request.url.startsWith(self.location.origin)) {
        return;
    }

    // Navigation requests: network-first with offline fallback
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    // Static assets (build output): cache-first
    if (request.url.includes('/build/')) {
        event.respondWith(
            caches.match(request).then((cached) => {
                if (cached) return cached;
                return fetch(request).then((response) => {
                    if (response.ok) {
                        const clone = response.clone();
                        caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
                    }
                    return response;
                });
            })
        );
        return;
    }
});

// Push notification handler (wired up in Phase 2)
self.addEventListener('push', (event) => {
    if (!event.data) return;

    const data = event.data.json();
    const options = {
        body: data.body || '',
        icon: data.icon || '/icons/icon-192.png',
        badge: '/icons/icon-192.png',
        tag: data.tag || 'default',
        data: { url: data.url || '/' },
        renotify: true,
    };

    event.waitUntil(self.registration.showNotification(data.title || 'Apex CRM', options));
});

// Notification click: open/focus the app at the deep link URL
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = event.notification.data?.url || '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clients) => {
            // Focus existing tab if open
            for (const client of clients) {
                if (client.url.includes(self.location.origin) && 'focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            // Otherwise open new window
            return self.clients.openWindow(url);
        })
    );
});
```

**Step 3: Create PWA registration script**

Create `resources/js/pwa.js`:

```javascript
// Register service worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', async () => {
        try {
            const registration = await navigator.serviceWorker.register('/sw.js');

            // Listen for updates
            registration.addEventListener('updatefound', () => {
                const newWorker = registration.installing;
                if (!newWorker) return;

                newWorker.addEventListener('statechange', () => {
                    if (newWorker.state === 'installed' && navigator.serviceWorker.controller) {
                        // New version available — dispatch event for UI toast
                        window.dispatchEvent(new CustomEvent('sw-update-available', {
                            detail: { registration }
                        }));
                    }
                });
            });
        } catch (err) {
            console.error('SW registration failed:', err);
        }
    });

    // When new SW takes over, reload page
    navigator.serviceWorker.addEventListener('controllerchange', () => {
        window.location.reload();
    });
}

// Capture Android install prompt
let deferredPrompt = null;

window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault();
    deferredPrompt = e;
    window.dispatchEvent(new CustomEvent('pwa-installable'));
});

window.addEventListener('appinstalled', () => {
    deferredPrompt = null;
    window.dispatchEvent(new CustomEvent('pwa-installed'));
    localStorage.setItem('pwa-installed', 'true');
});

// Expose install trigger for Alpine/Livewire
window.pwaInstall = async () => {
    if (!deferredPrompt) return false;
    deferredPrompt.prompt();
    const { outcome } = await deferredPrompt.userChoice;
    deferredPrompt = null;
    return outcome === 'accepted';
};

// Detect platform for install instructions
window.getPwaPlatform = () => {
    const ua = navigator.userAgent;
    const isIos = /iPad|iPhone|iPod/.test(ua);
    const isAndroid = /Android/.test(ua);
    const isSafari = /Safari/.test(ua) && !/Chrome/.test(ua);
    const isChrome = /Chrome/.test(ua) && !/Edge/.test(ua);
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;

    if (isStandalone) return 'installed';
    if (isIos && isSafari) return 'ios-safari';
    if (isIos && !isSafari) return 'ios-chrome'; // Chrome/FF on iOS
    if (isAndroid && isChrome) return 'android';
    return 'desktop';
};

// Expose SW update trigger
window.pwaUpdate = () => {
    navigator.serviceWorker?.getRegistration().then((reg) => {
        reg?.waiting?.postMessage({ type: 'SKIP_WAITING' });
    });
};
```

**Step 4: Import pwa.js in app.js**

In `resources/js/app.js`, add at the top with other imports:

```javascript
import './pwa';
```

**Step 5: Build and test**

```bash
npm run build
```

Verify: Open browser DevTools → Application → Service Workers. SW should be registered. Application → Manifest should show app info.

**Step 6: Commit**

```bash
git add public/sw.js public/offline.html resources/js/pwa.js resources/js/app.js
git commit -m "feat: add service worker with caching, offline fallback, and push handler"
```

---

### Task 3: Install Banner Component

**Files:**
- Create: `resources/views/components/pwa/⚡install-banner.blade.php`
- Modify: `resources/views/layouts/app.blade.php` (or the layout that wraps authenticated pages)

**Step 1: Create install banner Livewire SFC**

Create `resources/views/components/pwa/⚡install-banner.blade.php`:

This is a Livewire SFC that:
- Only renders for internal users (`auth()->user()->isInternal()`)
- Uses Alpine.js to listen for `pwa-installable` and `sw-update-available` events
- Detects platform via `getPwaPlatform()`
- Shows appropriate install UI:
  - Android: "Install Apex CRM" button that calls `pwaInstall()`
  - iOS Safari: Instructions overlay with Share → Add to Home Screen animation
  - iOS Chrome: "Open in Safari for the best experience" message
  - Desktop: Subtle install button
- Shows "Update available — Refresh" toast when SW updates
- Dismissible, stores dismissal in localStorage for 7 days
- Hidden if already installed (standalone mode)

**Step 2: Include in app layout**

Add `@livewire('pwa.install-banner')` in the main app layout, just inside the body tag.

**Step 3: Test on mobile**

Test on Android Chrome: should see install banner.
Test on iOS Safari: should see instructions.
Test on desktop: should see subtle prompt.

**Step 4: Commit**

```bash
git add resources/views/components/pwa/ resources/views/layouts/
git commit -m "feat: add PWA install banner with platform detection"
```

---

### Task 4: Deploy Auto-Update

**Files:**
- Modify: `deploy.sh`

**Step 1: Add SW version update to deploy script**

In `deploy.sh`, add after `npm run build`:

```bash
# Update service worker version to bust cache
sed -i "s/CACHE_VERSION = .*/CACHE_VERSION = '$(git rev-parse --short HEAD)';/" public/sw.js
```

**Step 2: Commit**

```bash
git add deploy.sh
git commit -m "feat: auto-update service worker version on deploy"
```

---

## Phase 2: Push Notifications

### Task 5: Install web-push package and config

**Files:**
- Modify: `composer.json` (via composer require)
- Create: `config/webpush.php`
- Modify: `.env.example`

**Step 1: Install package**

```bash
composer require minishlink/web-push
```

**Step 2: Generate VAPID keys**

```bash
php artisan tinker --execute="
\$keys = \Minishlink\WebPush\VAPID::createVapidKeys();
echo 'VAPID_PUBLIC_KEY=' . \$keys['publicKey'] . PHP_EOL;
echo 'VAPID_PRIVATE_KEY=' . \$keys['privateKey'] . PHP_EOL;
"
```

Add the output to `.env` and `.env.example`.

**Step 3: Create config file**

Create `config/webpush.php`:

```php
<?php

return [
    'vapid' => [
        'subject' => env('APP_URL'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],
];
```

**Step 4: Commit**

```bash
git add config/webpush.php .env.example composer.json composer.lock
git commit -m "feat: install web-push package and VAPID config"
```

---

### Task 6: Push Subscriptions Migration + Model

**Files:**
- Create: migration `create_push_subscriptions_table`
- Create: `app/Models/PushSubscription.php`
- Modify: `app/Models/User.php` — add relationship

**Step 1: Create migration**

```bash
php artisan make:migration create_push_subscriptions_table --no-interaction
```

Schema:
```php
Schema::create('push_subscriptions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->text('endpoint')->unique();
    $table->string('public_key');
    $table->string('auth_token');
    $table->string('content_encoding')->default('aesgcm');
    $table->string('user_agent')->nullable();
    $table->timestamps();

    $table->index('user_id');
});
```

**Step 2: Create model**

```bash
php artisan make:model PushSubscription --no-interaction
```

Set fillable, relationship to User.

**Step 3: Add relationship to User model**

```php
public function pushSubscriptions(): HasMany
{
    return $this->hasMany(PushSubscription::class);
}
```

**Step 4: Run migration**

```bash
php artisan migrate
```

**Step 5: Commit**

```bash
git add app/Models/PushSubscription.php app/Models/User.php database/migrations/*push_subscriptions*
git commit -m "feat: add push_subscriptions table and model"
```

---

### Task 7: Notification Preferences on Users

**Files:**
- Create: migration `add_notification_preferences_to_users_table`
- Modify: `app/Models/User.php` — add cast and helper methods

**Step 1: Create migration**

```bash
php artisan make:migration add_notification_preferences_to_users_table --no-interaction
```

```php
Schema::table('users', function (Blueprint $table) {
    $table->json('notification_preferences')->nullable()->after('remember_token');
});
```

**Step 2: Add cast and helper to User model**

Add to `casts()`:
```php
'notification_preferences' => 'array',
```

Add helper methods:

```php
/**
 * Default notification preferences — all channels enabled.
 */
public static function defaultNotificationPreferences(): array
{
    return [
        'estimates' => ['email' => true, 'push' => true],
        'chat' => ['email' => true, 'push' => true],
        'email_messages' => ['email' => true, 'push' => true],
        'jobs' => ['email' => true, 'push' => true],
        'tasks' => ['email' => true, 'push' => true],
        'payments' => ['email' => true, 'push' => true],
        'documents' => ['email' => true, 'push' => true],
        'general' => ['email' => true, 'push' => true],
    ];
}

/**
 * Get merged preferences (user overrides + defaults).
 */
public function getNotificationPreference(string $category, string $channel): bool
{
    $prefs = $this->notification_preferences ?? [];
    $defaults = self::defaultNotificationPreferences();

    return $prefs[$category][$channel] ?? $defaults[$category][$channel] ?? true;
}

/**
 * Check if user wants push for a category.
 */
public function wantsPush(string $category): bool
{
    return $this->getNotificationPreference($category, 'push')
        && $this->pushSubscriptions()->exists();
}

/**
 * Check if user wants email for a category.
 */
public function wantsEmail(string $category): bool
{
    return $this->getNotificationPreference($category, 'email');
}
```

**Step 3: Run migration and commit**

```bash
php artisan migrate
git add app/Models/User.php database/migrations/*notification_preferences*
git commit -m "feat: add notification preferences to users table"
```

---

### Task 8: WebPush Notification Channel

**Files:**
- Create: `app/Channels/WebPushChannel.php`
- Create: `app/Channels/WebPushMessage.php`

**Step 1: Create WebPushMessage value object**

Create `app/Channels/WebPushMessage.php`:

```php
<?php

namespace App\Channels;

class WebPushMessage
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url = '/',
        public ?string $icon = null,
        public ?string $tag = null,
    ) {}

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $this->icon ?? '/icons/icon-192.png',
            'tag' => $this->tag ?? 'default',
        ];
    }
}
```

**Step 2: Create WebPushChannel**

Create `app/Channels/WebPushChannel.php`:

```php
<?php

namespace App\Channels;

use Illuminate\Notifications\Notification;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        $subscriptions = $notifiable->pushSubscriptions;

        if ($subscriptions->isEmpty()) {
            return;
        }

        /** @var WebPushMessage $message */
        $message = $notification->toWebPush($notifiable);
        $payload = json_encode($message->toArray());

        $auth = [
            'VAPID' => [
                'subject' => config('webpush.vapid.subject'),
                'publicKey' => config('webpush.vapid.public_key'),
                'privateKey' => config('webpush.vapid.private_key'),
            ],
        ];

        $webPush = new WebPush($auth);

        foreach ($subscriptions as $sub) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                    'contentEncoding' => $sub->content_encoding,
                ]),
                $payload
            );
        }

        // Send all queued notifications
        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) {
                // Clean up expired/invalid subscriptions
                \App\Models\PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            }
        }
    }
}
```

**Step 3: Write test**

Create `tests/Feature/WebPush/WebPushChannelTest.php`:

Test that:
- Channel sends to all user subscriptions
- Channel skips users with no subscriptions
- Expired subscriptions are cleaned up (mock the WebPush flush response)

**Step 4: Run tests and commit**

```bash
php artisan test --compact tests/Feature/WebPush/
git add app/Channels/ tests/Feature/WebPush/
git commit -m "feat: add WebPush notification channel"
```

---

### Task 9: Push Subscription Controller + Routes

**Files:**
- Create: `app/Http/Controllers/PushSubscriptionController.php`
- Modify: `routes/web.php`

**Step 1: Create controller**

```bash
php artisan make:controller PushSubscriptionController --no-interaction
```

Methods:
- `store(Request $request)` — validates endpoint/keys, creates subscription via `firstOrCreate` on endpoint, returns 201
- `destroy(Request $request)` — deletes subscription by endpoint, returns 204

Validation for store:
```php
$request->validate([
    'endpoint' => 'required|url|max:500',
    'keys.p256dh' => 'required|string',
    'keys.auth' => 'required|string',
]);
```

**Step 2: Add routes**

In `routes/web.php`, add inside the authenticated middleware group:

```php
Route::post('/push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
Route::delete('/push/unsubscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
```

**Step 3: Write test**

Test subscribe (creates record), duplicate subscribe (idempotent), unsubscribe (deletes), unauthenticated (401).

**Step 4: Commit**

```bash
git add app/Http/Controllers/PushSubscriptionController.php routes/web.php tests/Feature/WebPush/
git commit -m "feat: add push subscription endpoints"
```

---

### Task 10: Frontend Push Subscription

**Files:**
- Create: `resources/js/push.js`
- Modify: `resources/js/app.js`

**Step 1: Create push.js**

Handles:
- Requesting notification permission
- Subscribing to push with VAPID public key
- Sending subscription to server via `/push/subscribe`
- Providing `subscribeToPush()` and `unsubscribeFromPush()` functions for Alpine/Livewire

The VAPID public key is passed via a meta tag: `<meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key') }}">` (added to head partial).

**Step 2: Import in app.js**

```javascript
import './push';
```

**Step 3: Add VAPID meta tag to head partial**

In `resources/views/partials/head.blade.php`:

```html
@auth
    <meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key') }}">
@endauth
```

**Step 4: Build and test**

```bash
npm run build
```

Test: Open browser, grant notification permission, verify subscription created in `push_subscriptions` table.

**Step 5: Commit**

```bash
git add resources/js/push.js resources/js/app.js resources/views/partials/head.blade.php
git commit -m "feat: add frontend push subscription with VAPID"
```

---

### Task 11: Add toWebPush() to All Notification Classes

**Files:**
- Modify: All 59 files in `app/Notifications/`

**Step 1: Categorize notifications**

Map each notification to its preference category:
- `estimates`: EstimateAccepted, EstimateAmended, EstimatePackageUpdated, EstimateProposalSent, EstimateReadyForAgent, EstimateRejected, ProposalViewed, ReferralEstimateSigned
- `chat`: UnreadChatMessage, NewSupportChatNotification
- `email_messages`: (handled by email.received broadcast, not a notification class)
- `jobs`: CrewJobDispatched, JobCompleted, JobCreated, JobStageAdvanced, CustomerConfirmedColors, CustomerConfirmedDate, CustomerProposedDate, SchedulePublished, TerritoryScheduleAssigned
- `tasks`: TaskAssigned
- `payments`: PaymentReceived, PayStubAvailable, InvoiceSent
- `documents`: DocumentExpiring, DocumentRejected, DocumentUploadedForReview, ContractSentForSigning
- `general`: Everything else (invitations, meetings, referrals, crew onboarding, PTO, video calls, etc.)

**Step 2: Update each notification class**

For each class:

1. Add `use App\Channels\WebPushChannel;` and `use App\Channels\WebPushMessage;` imports
2. Add a `public string $pushCategory = 'estimates';` property (set per class)
3. Update `via()` to include WebPushChannel conditionally:

```php
public function via(object $notifiable): array
{
    $channels = [];

    if ($notifiable->wantsEmail($this->pushCategory)) {
        $channels[] = 'mail';
    }

    if ($notifiable->wantsPush($this->pushCategory)) {
        $channels[] = WebPushChannel::class;
    }

    // Keep database if it was already there
    $channels[] = 'database';

    return $channels;
}
```

4. Add `toWebPush()` method:

```php
public function toWebPush(object $notifiable): WebPushMessage
{
    return new WebPushMessage(
        title: 'Estimate Accepted',
        body: "{$this->estimate->deal->customer->full_name} accepted estimate #{$this->estimate->estimate_number}",
        url: "/estimates/{$this->estimate->id}",
        tag: "estimate-{$this->estimate->id}",
    );
}
```

Each notification gets a contextual title, body, deep-link URL, and grouping tag.

**Important**: Some notifications only had `'mail'` in `via()`. Preserve existing behavior — only add push, don't remove existing channels. For notifications that didn't have `'database'`, don't add it.

**Step 3: Handle notifications that don't have a notifiable with preferences**

Some notifications go to users who may not have the `wantsPush()` method (e.g., guest notifications). Guard with `method_exists()` or check user type.

**Step 4: Write a test that verifies toWebPush exists on key notifications**

Test that EstimateAccepted, UnreadChatMessage, CrewJobDispatched, PaymentReceived each return a valid WebPushMessage.

**Step 5: Commit in batches**

Commit by category (estimates, chat, jobs, etc.) to keep diffs reviewable:

```bash
git commit -m "feat: add push notifications to estimate notification classes"
git commit -m "feat: add push notifications to chat notification classes"
# etc.
```

---

### Task 12: Notification Preferences Settings Page

**Files:**
- Create: `resources/views/components/settings/⚡notification-preferences.blade.php`
- Modify: `resources/views/pages/settings/layout.blade.php` (add nav item)

**Step 1: Create Livewire SFC**

The component:
- Loads user's notification preferences (merged with defaults)
- Shows a grid of categories × channels (email toggle, push toggle)
- Push toggles disabled with explanation if user has no push subscriptions
- Lists registered push devices with Remove button
- Save button updates `notification_preferences` on user model
- Uses Flux UI toggle/switch components

**Step 2: Add route**

```php
Route::livewire('/settings/notifications', 'settings.notification-preferences')
    ->name('settings.notifications');
```

**Step 3: Add to settings navigation**

Add "Notifications" link in the settings layout sidebar navigation.

**Step 4: Write test**

Test: renders for internal user, saves preferences, toggles persist on reload.

**Step 5: Commit**

```bash
git add resources/views/components/settings/⚡notification-preferences.blade.php routes/web.php
git commit -m "feat: add notification preferences settings page"
```

---

## Phase 3: Biometric Authentication (WebAuthn)

### Task 13: Install WebAuthn package

**Files:**
- Modify: `composer.json` (via composer require)

**Step 1: Install**

```bash
composer require web-auth/webauthn-lib
```

**Step 2: Commit**

```bash
git add composer.json composer.lock
git commit -m "feat: install web-auth/webauthn-lib for biometric login"
```

---

### Task 14: WebAuthn Credentials Migration + Model

**Files:**
- Create: migration `create_webauthn_credentials_table`
- Create: `app/Models/WebauthnCredential.php`
- Modify: `app/Models/User.php`

**Step 1: Create migration**

```bash
php artisan make:migration create_webauthn_credentials_table --no-interaction
```

```php
Schema::create('webauthn_credentials', function (Blueprint $table) {
    $table->string('credential_id')->primary();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->text('public_key');
    $table->unsignedBigInteger('counter')->default(0);
    $table->string('device_name');
    $table->json('transports')->nullable();
    $table->timestamp('last_used_at')->nullable();
    $table->timestamps();

    $table->index('user_id');
});
```

**Step 2: Create model**

Fillable fields, relationship to User, cast `transports` to array.

**Step 3: Add relationship to User**

```php
public function webauthnCredentials(): HasMany
{
    return $this->hasMany(WebauthnCredential::class);
}
```

**Step 4: Run migration and commit**

```bash
php artisan migrate
git add app/Models/WebauthnCredential.php app/Models/User.php database/migrations/*webauthn*
git commit -m "feat: add webauthn_credentials table and model"
```

---

### Task 15: WebAuthn Service

**Files:**
- Create: `app/Services/WebAuthnService.php`

**Step 1: Create service**

Wraps `web-auth/webauthn-lib` with methods:

- `generateRegistrationOptions(User $user)` — creates PublicKeyCredentialCreationOptions (challenge, user info, relying party, existing credential exclusions)
- `verifyRegistration(User $user, array $response, string $challenge)` — verifies the registration response, stores credential
- `generateAuthenticationOptions(?string $credentialId = null)` — creates PublicKeyCredentialRequestOptions
- `verifyAuthentication(array $response, string $challenge)` — verifies assertion, returns User, updates counter + last_used_at

Relying party: `config('app.name')` with origin from `config('app.url')`.
Challenge stored in session with 60-second TTL.

**Step 2: Write tests**

Test registration and authentication flows with mocked credential responses.

**Step 3: Commit**

```bash
git add app/Services/WebAuthnService.php tests/Feature/WebAuthn/
git commit -m "feat: add WebAuthn service for biometric credential management"
```

---

### Task 16: WebAuthn Controller + Routes

**Files:**
- Create: `app/Http/Controllers/WebAuthnController.php`
- Modify: `routes/web.php`

**Step 1: Create controller**

Methods:
- `registerOptions(Request $request)` — returns JSON registration challenge (requires auth)
- `register(Request $request)` — verifies and stores credential (requires auth)
- `loginOptions(Request $request)` — returns JSON authentication challenge (no auth required, accepts optional email to scope credentials)
- `login(Request $request)` — verifies assertion, logs user in via appropriate guard

**Step 2: Add routes**

```php
// Authenticated - credential registration
Route::middleware('auth')->group(function () {
    Route::post('/webauthn/register/options', [WebAuthnController::class, 'registerOptions']);
    Route::post('/webauthn/register', [WebAuthnController::class, 'register']);
});

// Unauthenticated - credential login
Route::post('/webauthn/login/options', [WebAuthnController::class, 'loginOptions']);
Route::post('/webauthn/login', [WebAuthnController::class, 'login']);
```

**Step 3: Write tests**

Test: register flow (creates credential), login flow (authenticates user), invalid credential (401), counter increment.

**Step 4: Commit**

```bash
git add app/Http/Controllers/WebAuthnController.php routes/web.php tests/Feature/WebAuthn/
git commit -m "feat: add WebAuthn registration and login endpoints"
```

---

### Task 17: Frontend WebAuthn JS

**Files:**
- Create: `resources/js/webauthn.js`
- Modify: `resources/js/app.js`

**Step 1: Create webauthn.js**

Provides:
- `webauthnRegister()` — calls `/webauthn/register/options`, triggers browser biometric, posts result to `/webauthn/register`
- `webauthnLogin(email?)` — calls `/webauthn/login/options`, triggers biometric, posts to `/webauthn/login`, redirects on success
- `webauthnSupported()` — returns boolean for feature detection
- `hasCredential()` — checks if conditional mediation is available (device has a stored credential for this origin)

Uses the standard Web Authentication API (`navigator.credentials.create()` / `navigator.credentials.get()`).

Handles base64url encoding/decoding for credential transport.

**Step 2: Import in app.js**

```javascript
import './webauthn';
```

**Step 3: Build and commit**

```bash
npm run build
git add resources/js/webauthn.js resources/js/app.js
git commit -m "feat: add frontend WebAuthn registration and login"
```

---

### Task 18: Biometric Login Page Integration

**Files:**
- Modify: `resources/views/pages/auth/login.blade.php`

**Step 1: Update login page**

Add Alpine.js logic to detect WebAuthn support and credential availability:
- On page load, check `webauthnSupported()` and attempt conditional mediation
- If credential detected: show biometric button prominently ("Sign in with Face ID" / "Sign in with fingerprint"), password form collapsed with "Use password instead" link
- If no credential: normal password form with subtle note ("Set up biometric login in Settings")
- Biometric button calls `webauthnLogin()`, shows loading state during verification
- On failure: expand password form, show error message

The button text adapts to platform:
- iOS: "Sign in with Face ID" (or Touch ID on older devices)
- Android: "Sign in with fingerprint"
- Desktop: "Sign in with biometrics"

**Step 2: Write Livewire/browser test**

Test that login page renders with biometric option when appropriate.

**Step 3: Commit**

```bash
git add resources/views/pages/auth/login.blade.php
git commit -m "feat: add biometric-first login flow"
```

---

### Task 19: Biometric Devices Settings Page

**Files:**
- Create: `resources/views/components/settings/⚡biometric-devices.blade.php`
- Modify: settings layout navigation

**Step 1: Create Livewire SFC**

The component:
- Lists user's registered WebAuthn credentials (device name, registered date, last used)
- "Register this device" button that calls `webauthnRegister()` via Alpine
- Remove button per device (confirms, then deletes credential)
- Shows "Your browser doesn't support biometric login" if WebAuthn unsupported

**Step 2: Add route**

```php
Route::livewire('/settings/biometric', 'settings.biometric-devices')
    ->name('settings.biometric');
```

**Step 3: Add to settings nav**

Add "Biometric Login" link in settings sidebar.

**Step 4: Write test and commit**

```bash
git add resources/views/components/settings/⚡biometric-devices.blade.php routes/web.php
git commit -m "feat: add biometric devices management settings page"
```

---

## Phase 4: Integration & Polish

### Task 20: Email Received Push Notification

**Files:**
- Modify: `app/Events/EmailReceived.php` or `app/Jobs/ProcessInboundEmailJob.php`

The email system uses broadcast events (not notification classes) for real-time updates. We need to also send a push notification when an email is received.

**Step 1: Create EmailReceivedNotification**

Create `app/Notifications/EmailReceivedNotification.php` that sends a push notification:

```php
public function via(object $notifiable): array
{
    if ($notifiable->wantsPush('email_messages')) {
        return [WebPushChannel::class];
    }
    return [];
}

public function toWebPush(object $notifiable): WebPushMessage
{
    return new WebPushMessage(
        title: $this->message->from_name ?? $this->message->from_address,
        body: $this->message->subject,
        url: "/email?threadId={$this->message->email_thread_id}",
        tag: "email-thread-{$this->message->email_thread_id}",
    );
}
```

**Step 2: Dispatch from ProcessInboundEmailJob**

After the `event(new EmailReceived($message))` line, add:

```php
$account->user->notify(new \App\Notifications\EmailReceivedNotification($message));
```

**Step 3: Commit**

```bash
git add app/Notifications/EmailReceivedNotification.php app/Jobs/ProcessInboundEmailJob.php
git commit -m "feat: add push notification for inbound emails"
```

---

### Task 21: Test Push End-to-End

**Manual testing checklist:**

1. Log in as Matt on phone browser
2. See install banner → install PWA
3. Grant notification permission → verify subscription in DB
4. Go to Settings → Notifications → verify preferences UI
5. Go to Settings → Biometric → register device
6. Log out → see biometric login button → log in with Face ID/fingerprint
7. Have someone send email to matt@paintingmadeeasydenver.com → verify push notification appears on phone
8. Tap notification → verify it opens the app to the email thread
9. Close app → send another email → verify push arrives even when app is closed
10. Deploy a code change → next time user opens app, verify "Update available" toast appears

---

### Task 22: Run Full Test Suite

```bash
php artisan test --compact
```

Ensure all existing tests still pass alongside the new push/webauthn tests.

**Final commit:**

```bash
git add -A
git commit -m "test: add comprehensive push notification and WebAuthn tests"
```

---

## Summary

| Phase | Tasks | What it delivers |
|-------|-------|-----------------|
| 1: PWA Foundation | 1-4 | Installable app, offline support, auto-updates |
| 2: Push Notifications | 5-12 | Push to all 59 notification types, user preferences |
| 3: Biometric Auth | 13-19 | Face ID / fingerprint login with password fallback |
| 4: Integration | 20-22 | Email push, end-to-end testing, polish |

**Total: 22 tasks across 4 phases.**

**Gated to internal users initially.** After testing, expand by changing `isInternal()` checks to include other user types.

# PWA + Push Notifications + Biometric Login Design

**Date**: 2026-02-23
**Status**: Approved
**Scope**: Internal users first, then expand to all user types

## Goal

Turn the web app into an installable PWA with native push notifications and biometric (Face ID / fingerprint) login. Replace email notification noise with instant push. Let users choose their preferred notification delivery method.

## Approach

- **PWA**: Service worker + web app manifest for installability and auto-updates
- **Push**: Web Push API with VAPID keys (self-hosted, no external services)
- **Biometrics**: WebAuthn/FIDO2 passkeys for Face ID, Touch ID, and fingerprint login
- **Future**: NativePHP Mobile as a native app wrapper when it matures

---

## Section 1: PWA Foundation

### Web App Manifest (`public/manifest.json`)

- Name: "Apex CRM", short name: "Apex"
- Display mode: `standalone` (hides browser chrome)
- Theme color matches app's blue/zinc palette
- Icons: 192px and 512px (maskable for Android adaptive icons)
- Start URL: `/?source=pwa`
- Scope: `/`

### Service Worker (`public/sw.js`)

- **Cache strategy**: Network-first for HTML/API, cache-first for static assets
- **Auto-update**: `CACHE_VERSION` constant updated by deploy script with git short hash. Browser detects change, installs new SW in background, activates on next navigation.
- **Offline fallback**: Simple offline page when no connection
- **Push handler**: Receives push events, shows native OS notifications, deep-links on tap

### Install Prompt

- **Android**: Capture `beforeinstallprompt`, show banner with Install button
- **iOS Safari**: Detect Safari + iOS, show instructions overlay (Tap Share -> Add to Home Screen)
- **Chrome on iOS**: Show message directing user to open in Safari for full PWA support
- Gated to internal users only (check `user-type` meta tag)
- Dismissible, remembers dismissal for 7 days via localStorage
- Never shows again after install

### Deploy Auto-Update

- Forge deploy script runs: `sed -i "s/CACHE_VERSION = .*/CACHE_VERSION = '$(git rev-parse --short HEAD)';/" public/sw.js`
- On next visit, browser detects SW change, installs update in background
- Toast shown: "Update available" with Refresh button
- If ignored, auto-applies on next visit

---

## Section 2: Push Notifications

### Database: `push_subscriptions`

| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | FK users | |
| endpoint | text, unique | Push service URL |
| public_key | string | P-256 public key |
| auth_token | string | Auth secret |
| content_encoding | string | Default 'aesgcm' |
| user_agent | string nullable | Device identification |
| timestamps | | |

A user can have multiple subscriptions (one per device).

### VAPID Keys

- Generated once via `web-push` PHP library
- Stored in `.env`: `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`
- Config: `config/webpush.php`

### PHP Package: `minishlink/web-push`

Free, self-hosted. No Firebase, no OneSignal.

### WebPushChannel

Custom Laravel notification channel:

1. Check if user has push subscriptions
2. Build payload: title, body, icon, url (deep link), tag (grouping)
3. Send to each device via web-push library
4. Auto-cleanup failed/expired subscriptions (410 Gone = delete)

### Notification Integration

All 47 existing notification classes get:
- `toWebPush($notifiable)` method returning push payload
- `via()` method updated to check user preferences: `['mail', 'database', 'web-push']`

Example payload (EstimateAccepted):
```
title: "Estimate Accepted"
body: "Jane Smith accepted estimate #EST-001 ($2,262)"
url: "/estimates/5"
icon: "/icons/estimate-accepted.png"
tag: "estimate-5"
```

### Service Worker Push Handler

- Shows native OS notification from push event
- On click: opens app to the `url` from payload (deep link)
- If app already open: focuses that tab
- Groups by `tag` — multiple updates to same item replace, don't stack

### User Notification Preferences

- `notification_preferences` JSON column on `users` table
- Per-category toggles: Email / Push / Both
- Categories: Estimates, Chat, Email, Jobs, Tasks, Payments, Documents
- Default: both enabled
- Push toggles greyed out if no subscriptions with "Install the app to enable"
- Settings page: manage devices list with Remove button

### Subscribe/Unsubscribe Flow

1. After PWA install, app requests notification permission
2. If granted, browser creates subscription with VAPID public key
3. Frontend POSTs to `POST /api/push/subscribe`
4. Server stores in `push_subscriptions`
5. Manage from Settings: list devices, remove individual subscriptions

---

## Section 3: Biometric Authentication (WebAuthn/Passkeys)

### Database: `webauthn_credentials`

| Column | Type | Notes |
|--------|------|-------|
| id | string PK | Credential ID from authenticator |
| user_id | FK users | |
| public_key | text | COSE public key |
| counter | bigint | Replay prevention |
| device_name | string | e.g. "iPhone 15" |
| transports | json | e.g. ["internal", "hybrid"] |
| last_used_at | timestamp | |
| timestamps | | |

A user can have multiple credentials (one per device).

### PHP Package: `web-auth/webauthn-lib`

Standard WebAuthn library. Wrapped in `WebAuthnService`.

### Registration Flow (Settings -> Security -> Enable Biometric Login)

1. User taps "Enable Biometric Login"
2. Server generates challenge (`POST /webauthn/register/options`)
3. Browser triggers native biometric (Face ID / fingerprint)
4. Browser returns signed credential
5. Frontend sends to `POST /webauthn/register` -> server stores credential
6. Device listed in settings: "iPhone — registered 2 minutes ago"

### Login Flow (Biometric-First on PWA)

1. Login page detects stored credential via `navigator.credentials.get()` with conditional mediation
2. If credential exists: biometric button shown prominently, password form collapsed as "Use password instead"
3. User taps biometric -> Face ID / fingerprint triggered
4. Browser returns signed assertion
5. Frontend sends to `POST /webauthn/login` -> server verifies
6. Server authenticates via appropriate guard (internal/agent/customer/crew)
7. If biometric fails: password form expands, normal login flow

### Login Page UX by Scenario

- **PWA with registered credential**: Biometric button primary, password secondary
- **PWA without credential**: Normal password form + note to set up in Settings
- **Desktop browser**: Normal password form, biometric available if device supports
- **Chrome on iOS**: Biometric works (WebAuthn works), just no push

### Security

- Phishing-resistant (credential bound to origin)
- One-time-use challenge with 60-second expiry
- Counter verification prevents cloning
- Per-device credentials — revoking one doesn't affect others
- Existing TOTP 2FA continues alongside WebAuthn

---

## Section 4: Auto-Update & Offline

### Service Worker Versioning

- `CACHE_VERSION` constant = git short hash
- Deploy script updates it automatically
- Browser detects change -> installs new SW -> activates on next load
- Toast: "Update available" with Refresh button
- Auto-applies if user ignores

### Offline Behavior

- Static assets cached on install (app shell loads instantly)
- API calls: network-first, show "You're offline" if no connection
- No complex offline data sync (CRM needs live data)

---

## New Files

| File | Purpose |
|------|---------|
| `public/manifest.json` | PWA manifest |
| `public/sw.js` | Service worker |
| `public/icons/` | App icons (192px, 512px, maskable) |
| `public/offline.html` | Offline fallback |
| `config/webpush.php` | VAPID keys config |
| `migration: create_push_subscriptions_table` | Device push tokens |
| `migration: create_webauthn_credentials_table` | Biometric credentials |
| `migration: add_notification_preferences_to_users` | Per-user prefs |
| `app/Channels/WebPushChannel.php` | Laravel notification channel |
| `app/Services/WebAuthnService.php` | WebAuthn wrapper |
| `app/Http/Controllers/PushSubscriptionController.php` | Subscribe/unsubscribe |
| `app/Http/Controllers/WebAuthnController.php` | Register/login |
| `resources/js/pwa.js` | Install prompt + SW registration |
| `resources/js/webauthn.js` | Browser WebAuthn API |
| `resources/views/components/pwa/⚡install-banner.blade.php` | Install prompt |
| `resources/views/components/settings/⚡notification-preferences.blade.php` | Push/email toggles |
| `resources/views/components/settings/⚡biometric-devices.blade.php` | Manage credentials |
| Login page updates | Biometric-first flow |
| 47 notification classes | Add `toWebPush()` to each |

## Modified Files

| File | Change |
|------|--------|
| `resources/views/partials/head.blade.php` | Add manifest link, SW registration |
| `resources/js/app.js` | Import pwa.js, webauthn.js |
| `vite.config.js` | Add new JS entry points if needed |
| Login Blade views | Add biometric button |
| `routes/web.php` | WebAuthn + push subscription routes |
| User model | Add `notification_preferences` cast, relationships |
| All 47 notification classes | Add `toWebPush()`, update `via()` |
| Forge deploy script | Add SW version update line |
| `.env` / `.env.example` | Add VAPID keys |

## Rollout Plan

1. Build everything, gate behind `user->isInternal()`
2. Matt tests on phone — install PWA, enable biometrics, verify push
3. Expand to agents, then customers, then crew
4. Add per-user notification preference settings
5. Eventually offer "push only, no email" option

## Platform Compatibility

| Feature | Android Chrome | iOS Safari | iOS Chrome | Desktop |
|---------|---------------|------------|------------|---------|
| PWA Install | Native prompt | Manual (Share -> Add) | Not supported | Supported |
| Push Notifications | Full support | iOS 16.4+ (PWA only) | Not supported | Full support |
| Biometric Login | Fingerprint/face | Face ID/Touch ID | Face ID/Touch ID | Windows Hello/Touch ID |
| Auto-Update | Automatic | Automatic | N/A (not PWA) | Automatic |

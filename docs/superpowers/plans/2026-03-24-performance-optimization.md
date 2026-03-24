# Performance Optimization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reach 90+ PageSpeed mobile score while keeping all features — Bunny CDN for images, deferred third-party scripts, lazy-loaded map, self-hosted fonts, scoped CSS, cache headers.

**Architecture:** Seven optimization layers applied to the landing page and app-wide image serving. Bunny CDN pull zone serves optimized images from Wasabi S3. Third-party scripts defer until user interaction. Fonts self-hosted. Leaflet lazy-loaded. Landing page gets scoped CSS via dedicated head partial.

**Tech Stack:** Bunny CDN + Optimizer, Tailwind v4 `@source`, IntersectionObserver, Blade stacks, Nginx cache headers.

**Spec:** `docs/superpowers/specs/2026-03-24-performance-optimization-design.md`

---

### Task 1: CDN Helper Functions

**Files:**
- Modify: `app/helpers.php`
- Modify: `config/services.php:73` (after `meta` block)
- Modify: `.env.example`
- Test: `tests/Unit/CdnHelperTest.php`

- [ ] **Step 1: Write failing tests for cdn_image helper**

```php
// tests/Unit/CdnHelperTest.php
<?php

use Illuminate\Support\Facades\Config;

it('returns direct storage url when bunny cdn is disabled', function () {
    Config::set('services.bunny.enabled', false);
    Config::set('services.bunny.cdn_url', '');

    $url = cdn_image('photos/test.jpg');

    expect($url)->not->toContain('cdn.');
});

it('returns bunny cdn url with quality param when enabled', function () {
    Config::set('services.bunny.enabled', true);
    Config::set('services.bunny.cdn_url', 'https://cdn.example.com');

    $url = cdn_image('photos/test.jpg');

    expect($url)->toStartWith('https://cdn.example.com/photos/test.jpg');
    expect($url)->toContain('quality=80');
});

it('includes width param when specified', function () {
    Config::set('services.bunny.enabled', true);
    Config::set('services.bunny.cdn_url', 'https://cdn.example.com');

    $url = cdn_image('photos/test.jpg', 800);

    expect($url)->toContain('width=800');
    expect($url)->toContain('quality=80');
});

it('includes cache-bust param when updatedAt provided', function () {
    Config::set('services.bunny.enabled', true);
    Config::set('services.bunny.cdn_url', 'https://cdn.example.com');

    $ts = now();
    $url = cdn_image('photos/test.jpg', updatedAt: $ts);

    expect($url)->toContain('v=' . $ts->timestamp);
});

it('generates srcset with multiple widths', function () {
    Config::set('services.bunny.enabled', true);
    Config::set('services.bunny.cdn_url', 'https://cdn.example.com');

    $srcset = cdn_srcset('photos/test.jpg', [400, 800]);

    expect($srcset)->toContain('400w');
    expect($srcset)->toContain('800w');
});

it('generates correct bunny token auth url', function () {
    Config::set('services.bunny.enabled', true);
    Config::set('services.bunny.cdn_url', 'https://cdn.example.com');
    Config::set('services.bunny.token_key', 'test-secret-key');

    $url = cdn_private('photos/private.jpg', 60);

    expect($url)->toStartWith('https://cdn.example.com/photos/private.jpg');
    expect($url)->toContain('token=');
    expect($url)->toContain('expires=');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Unit/CdnHelperTest.php`
Expected: FAIL — `cdn_image` function not defined

- [ ] **Step 3: Add Bunny config to services.php**

Add after the `'meta'` block at `config/services.php:75`:

```php
'bunny' => [
    'cdn_url' => env('BUNNY_CDN_URL', ''),
    'enabled' => env('BUNNY_CDN_ENABLED', false),
    'token_key' => env('BUNNY_TOKEN_KEY', ''),
],
```

- [ ] **Step 4: Add env vars to .env.example**

```
BUNNY_CDN_URL=
BUNNY_CDN_ENABLED=false
BUNNY_TOKEN_KEY=
```

- [ ] **Step 5: Add CDN helpers to app/helpers.php**

Append to `app/helpers.php`:

```php
if (! function_exists('cdn_image')) {
    /**
     * Generate a Bunny CDN optimized image URL.
     * Falls back to direct Storage URL when CDN is disabled.
     */
    function cdn_image(string $path, ?int $width = null, int $quality = 80, ?\Carbon\Carbon $updatedAt = null): string
    {
        if (! config('services.bunny.enabled') || ! config('services.bunny.cdn_url')) {
            return storage_url($path) ?? '';
        }

        $url = config('services.bunny.cdn_url') . '/' . ltrim($path, '/');
        $params = ['quality' => $quality];
        if ($width) {
            $params['width'] = $width;
        }
        if ($updatedAt) {
            $params['v'] = $updatedAt->timestamp;
        }

        return $url . '?' . http_build_query($params);
    }
}

if (! function_exists('cdn_srcset')) {
    /**
     * Generate a responsive srcset string for Bunny CDN images.
     */
    function cdn_srcset(string $path, array $widths = [400, 800, 1200], int $quality = 80): string
    {
        return collect($widths)
            ->map(fn ($w) => cdn_image($path, $w, $quality) . " {$w}w")
            ->implode(', ');
    }
}

if (! function_exists('cdn_private')) {
    /**
     * Generate a Bunny CDN token-authenticated URL for private images.
     */
    function cdn_private(string $path, int $expiresInMinutes = 60): string
    {
        if (! config('services.bunny.enabled') || ! config('services.bunny.cdn_url')) {
            return storage_url($path) ?? '';
        }

        $path = '/' . ltrim($path, '/');
        $expires = now()->addMinutes($expiresInMinutes)->timestamp;
        $securityKey = config('services.bunny.token_key');
        $hashableBase = $securityKey . $path . $expires;
        $token = rtrim(str_replace(['+', '/'], ['-', '_'], base64_encode(hash('sha256', $hashableBase, true))), '=');

        return config('services.bunny.cdn_url') . $path . '?token=' . $token . '&expires=' . $expires;
    }
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact tests/Unit/CdnHelperTest.php`
Expected: All 6 tests PASS

- [ ] **Step 7: Commit**

```bash
git add app/helpers.php config/services.php .env.example tests/Unit/CdnHelperTest.php
git commit -m "feat: add Bunny CDN helper functions for image optimization"
```

---

### Task 2: Self-Hosted Fonts

**Files:**
- Create: `public/fonts/instrument-sans-latin-400-normal.woff2`
- Create: `public/fonts/instrument-sans-latin-500-normal.woff2`
- Create: `public/fonts/instrument-sans-latin-600-normal.woff2`
- Modify: `resources/css/app.css:1` (add @font-face before existing content)
- Modify: `resources/views/partials/head-public.blade.php:40-41`
- Modify: `resources/views/partials/head.blade.php:40-41`

- [ ] **Step 1: Download font files**

```bash
mkdir -p public/fonts
curl -o public/fonts/instrument-sans-latin-400-normal.woff2 "https://fonts.bunny.net/instrument-sans/files/instrument-sans-latin-400-normal.woff2"
curl -o public/fonts/instrument-sans-latin-500-normal.woff2 "https://fonts.bunny.net/instrument-sans/files/instrument-sans-latin-500-normal.woff2"
curl -o public/fonts/instrument-sans-latin-600-normal.woff2 "https://fonts.bunny.net/instrument-sans/files/instrument-sans-latin-600-normal.woff2"
```

Verify: `ls -la public/fonts/*.woff2` — should show 3 files, each 10-30KB

- [ ] **Step 2: Add @font-face declarations to app.css**

Add BEFORE the first `@import` line in `resources/css/app.css`:

```css
@font-face {
    font-family: 'Instrument Sans';
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url('/fonts/instrument-sans-latin-400-normal.woff2') format('woff2');
}
@font-face {
    font-family: 'Instrument Sans';
    font-style: normal;
    font-weight: 500;
    font-display: swap;
    src: url('/fonts/instrument-sans-latin-500-normal.woff2') format('woff2');
}
@font-face {
    font-family: 'Instrument Sans';
    font-style: normal;
    font-weight: 600;
    font-display: swap;
    src: url('/fonts/instrument-sans-latin-600-normal.woff2') format('woff2');
}

```

- [ ] **Step 3: Replace external font loading in head-public.blade.php**

In `resources/views/partials/head-public.blade.php`, replace lines 35-36 (the preconnect + font link):

```diff
- <link rel="preconnect" href="https://fonts.bunny.net">
- <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
+ <link rel="preload" href="/fonts/instrument-sans-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
+ <link rel="preload" href="/fonts/instrument-sans-latin-500-normal.woff2" as="font" type="font/woff2" crossorigin>
```

- [ ] **Step 4: Do the same in head.blade.php for authenticated pages**

In `resources/views/partials/head.blade.php`, replace lines 40-41 (same change — preconnect + font link replaced with preloads).

- [ ] **Step 5: Build and verify**

Run: `npm run build`
Verify: Load any page in browser → DevTools Network → fonts should load from `/fonts/` not `fonts.bunny.net`

- [ ] **Step 6: Commit**

```bash
git add public/fonts/ resources/css/app.css resources/views/partials/head-public.blade.php resources/views/partials/head.blade.php
git commit -m "perf: self-host Instrument Sans fonts with font-display swap"
```

---

### Task 3: Deferred Third-Party Scripts

**Files:**
- Modify: `resources/views/components/layouts/guest.blade.php:4-20`
- Modify: `resources/views/partials/meta-pixel.blade.php`
- Modify: `resources/views/partials/messenger-widget.blade.php`

- [ ] **Step 1: Replace GTM/GA scripts with deferred loader in guest.blade.php**

Replace the entire `@production ... @endproduction` block in `<head>` (lines 4-20) with:

```blade
@production
<script>
(function() {
    var loaded = false;
    function loadAnalytics() {
        if (loaded) return;
        loaded = true;
        // Google Tag Manager
        (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
        new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
        j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
        'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','GTM-KPDL23JM');
        // Google Analytics
        var ga = document.createElement('script');
        ga.async = true;
        ga.src = 'https://www.googletagmanager.com/gtag/js?id=G-D4F4V2J77S';
        document.head.appendChild(ga);
        ga.onload = function() {
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', 'G-D4F4V2J77S');
        };
    }
    ['scroll','mousemove','touchstart','keydown'].forEach(function(e) {
        window.addEventListener(e, loadAnalytics, {once: true, passive: true});
    });
    setTimeout(loadAnalytics, 5000);
})();
</script>
@endproduction
```

**IMPORTANT**: Keep the `<noscript>` GTM iframe in `<body>` (line ~31) unchanged.

- [ ] **Step 2: Defer Facebook Pixel in meta-pixel.blade.php**

Replace `resources/views/partials/meta-pixel.blade.php` with:

```blade
@if(config('services.meta.pixel_id'))
{{-- FB Pixel: deferred until user interaction, noscript fallback stays inline --}}
<script>
(function() {
    var loaded = false;
    function loadPixel() {
        if (loaded) return;
        loaded = true;
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
    }
    ['scroll','mousemove','touchstart','keydown'].forEach(function(e) {
        window.addEventListener(e, loadPixel, {once: true, passive: true});
    });
    setTimeout(loadPixel, 5000);
})();
</script>
<noscript><img height="1" width="1" style="display:none" src="https://www.facebook.com/tr?id={{ config('services.meta.pixel_id') }}&ev=PageView&noscript=1"/></noscript>
@endif
```

- [ ] **Step 3: Defer Messenger Widget in messenger-widget.blade.php**

Replace `resources/views/partials/messenger-widget.blade.php` with:

```blade
{{-- Facebook Messenger Chat Plugin — deferred until user interaction --}}
@php
    $messengerSettings = \App\Models\MessengerSetting::getInstance();
@endphp

@if($messengerSettings->website_widget_enabled && $messengerSettings->page_id)
    <div id="fb-root"></div>
    <div id="fb-customer-chat"
        class="fb-customerchat"
        attribution="biz_inbox"
        page_id="{{ $messengerSettings->page_id }}"
        theme_color="{{ $messengerSettings->widget_theme_color ?? '#0084ff' }}"
        logged_in_greeting="{{ $messengerSettings->widget_greeting_logged_in ?? 'Hi! Need a painting quote?' }}"
        logged_out_greeting="{{ $messengerSettings->widget_greeting_logged_out ?? 'Hi! Need a painting quote?' }}">
    </div>
    <script>
    (function() {
        var loaded = false;
        function loadMessenger() {
            if (loaded) return;
            loaded = true;
            window.fbAsyncInit = function() {
                FB.init({ xfbml: true, version: 'v19.0' });
            };
            var d = document, s = 'script', id = 'facebook-jssdk';
            if (d.getElementById(id)) return;
            var js = d.createElement(s); js.id = id;
            js.src = 'https://connect.facebook.net/en_US/sdk/xfbml.customerchat.js';
            d.getElementsByTagName(s)[0].parentNode.insertBefore(js, d.getElementsByTagName(s)[0]);
        }
        ['scroll','mousemove','touchstart','keydown'].forEach(function(e) {
            window.addEventListener(e, loadMessenger, {once: true, passive: true});
        });
        setTimeout(loadMessenger, 5000);
    })();
    </script>
@endif
```

- [ ] **Step 4: Verify in browser**

Load landing page → DevTools Network → filter by `gtm.js`, `fbevents.js`, `customerchat.js`. They should NOT appear until you scroll or wait 5 seconds.

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/layouts/guest.blade.php resources/views/partials/meta-pixel.blade.php resources/views/partials/messenger-widget.blade.php
git commit -m "perf: defer third-party scripts until user interaction"
```

---

### Task 4: Lazy-Load Leaflet Service Area Map

**Files:**
- Modify: `resources/views/partials/service-area-map.blade.php`
- Modify: `resources/views/components/public/⚡landing-page.blade.php` (the @include call)

- [ ] **Step 1: Rewrite service-area-map.blade.php with lazy loading**

Replace the entire file `resources/views/partials/service-area-map.blade.php` with:

```blade
@php
    $mapId = 'service-area-map-' . uniqid();
    $locationiqKey = config('services.locationiq.key');
    $center = $center ?? [39.75, -104.99];
    $zoom = $zoom ?? 9;
    $highlight = $highlight ?? null;
    $lazy = $lazy ?? false;
    $mapAreas = $areas->filter(fn ($a) => $a->latitude && $a->longitude)->map(fn ($a) => [
        'city' => $a->city,
        'slug' => $a->slug,
        'lat' => (float) $a->latitude,
        'lng' => (float) $a->longitude,
    ])->values()->all();

    $company = \App\Models\CompanySetting::getInstance();
    $companyName = $company->company_name ?? config('app.name');
    $hqLat = 39.7392;
    $hqLng = -104.9903;
@endphp

<div id="{{ $mapId }}" class="w-full h-[400px] rounded-xl shadow-md z-0 bg-zinc-800 flex items-center justify-center">
    @if($lazy)
    <span class="text-zinc-400 text-sm">Loading map...</span>
    @endif
</div>

@if(!$lazy)
{{-- Eager load: current behavior for area pages --}}
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
@endif

<script>
function initMap_{{ str_replace('-', '_', $mapId) }}() {
    var mapEl = document.getElementById('{{ $mapId }}');
    if (!mapEl || mapEl._leaflet_id) return;
    mapEl.innerHTML = '';

    var map = L.map('{{ $mapId }}', {
        scrollWheelZoom: false,
    }).setView([{{ $center[0] }}, {{ $center[1] }}], {{ $zoom }});

    @if($locationiqKey)
        L.tileLayer('https://{s}-tiles.locationiq.com/v3/streets/r/{z}/{x}/{y}.png?key={{ $locationiqKey }}', {
            attribution: '&copy; <a href="https://locationiq.com">LocationIQ</a> &copy; <a href="https://www.openstreetmap.org/copyright">OSM</a>',
            subdomains: ['a', 'b', 'c'],
            maxZoom: 18,
        }).addTo(map);
    @else
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
            maxZoom: 18,
        }).addTo(map);
    @endif

    var areas = {!! json_encode($mapAreas) !!};
    var highlight = {!! json_encode($highlight) !!};

    areas.forEach(function (area) {
        var isHighlighted = highlight && area.slug === highlight;
        var marker = L.circleMarker([area.lat, area.lng], {
            radius: isHighlighted ? 10 : 7,
            fillColor: isHighlighted ? '#10b981' : '#2563eb',
            color: '#fff',
            weight: 2,
            fillOpacity: isHighlighted ? 1 : 0.85,
        }).addTo(map);

        var url = '{{ url("/areas") }}/' + area.slug;
        marker.bindPopup(
            '<div class="text-center">' +
            '<strong class="text-sm">' + area.city + '</strong><br>' +
            '<a href="' + url + '" class="text-blue-600 text-xs hover:underline">View Services &rarr;</a>' +
            '</div>'
        );

        if (isHighlighted) {
            marker.openPopup();
        }
    });

    var hqIcon = L.divIcon({
        className: '',
        html: '<div style="background:#f59e0b;width:18px;height:18px;border-radius:50%;border:3px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.3);display:flex;align-items:center;justify-content:center">' +
              '<svg width="10" height="10" viewBox="0 0 24 24" fill="#fff"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 010-5 2.5 2.5 0 010 5z"/></svg>' +
              '</div>',
        iconSize: [18, 18],
        iconAnchor: [9, 9],
    });
    L.marker([{{ $hqLat }}, {{ $hqLng }}], { icon: hqIcon, zIndexOffset: 1000 })
        .addTo(map)
        .bindPopup('<div class="text-center"><strong class="text-sm">{{ $companyName }}</strong><br><span class="text-xs text-zinc-500">Company HQ</span></div>');
}

@if($lazy)
(function() {
    var container = document.getElementById('{{ $mapId }}');
    if (!container) return;
    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                observer.disconnect();
                var css = document.createElement('link');
                css.rel = 'stylesheet';
                css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                document.head.appendChild(css);
                var js = document.createElement('script');
                js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                js.onload = function() { initMap_{{ str_replace('-', '_', $mapId) }}(); };
                document.head.appendChild(js);
            }
        });
    }, { rootMargin: '300px' });
    observer.observe(container);
})();
@else
document.addEventListener('DOMContentLoaded', function() { initMap_{{ str_replace('-', '_', $mapId) }}(); });
@endif
</script>
```

- [ ] **Step 2: Update landing page to pass lazy=true**

In `resources/views/components/public/⚡landing-page.blade.php`, find the `@include('partials.service-area-map'` call and add `'lazy' => true`:

Find the existing `@include('partials.service-area-map'` call (around line 1955) and add `'lazy' => true` to the existing parameters. Keep `'areas' => $footerAreas` and all other existing params — just add `'lazy' => true`.

- [ ] **Step 3: Verify in browser**

Load landing page → DevTools Network → filter `leaflet`. Should NOT appear until scrolling near the bottom map section.

- [ ] **Step 4: Commit**

```bash
git add resources/views/partials/service-area-map.blade.php "resources/views/components/public/⚡landing-page.blade.php"
git commit -m "perf: lazy-load Leaflet map on landing page via IntersectionObserver"
```

---

### Task 5: Scoped Landing CSS + Head Partial

**Files:**
- Create: `resources/css/landing.css`
- Create: `resources/views/partials/head-landing.blade.php`
- Modify: `resources/views/components/layouts/guest.blade.php`
- Modify: `resources/views/components/public/⚡landing-page.blade.php`
- Modify: `vite.config.js`

- [ ] **Step 1: Create landing.css**

Create `resources/css/landing.css` — copy the `@font-face` blocks, `@import`, `@theme`, `@custom-variant`, and custom CSS from `app.css`, but use a narrow `@source`:

```css
@font-face {
    font-family: 'Instrument Sans';
    font-style: normal;
    font-weight: 400;
    font-display: swap;
    src: url('/fonts/instrument-sans-latin-400-normal.woff2') format('woff2');
}
@font-face {
    font-family: 'Instrument Sans';
    font-style: normal;
    font-weight: 500;
    font-display: swap;
    src: url('/fonts/instrument-sans-latin-500-normal.woff2') format('woff2');
}
@font-face {
    font-family: 'Instrument Sans';
    font-style: normal;
    font-weight: 600;
    font-display: swap;
    src: url('/fonts/instrument-sans-latin-600-normal.woff2') format('woff2');
}

@import 'tailwindcss';
@import '../../vendor/livewire/flux/dist/flux.css';

@source '../views/components/public/⚡landing-page.blade.php';
@source '../views/components/layouts/guest.blade.php';
@source '../views/partials';
@source '../../vendor/livewire/flux/stubs/**/*.blade.php';

@custom-variant dark (&:where(.dark, .dark *));

@theme {
    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji', 'Segoe UI Symbol', 'Noto Color Emoji';

    --color-zinc-50: #fafafa;
    --color-zinc-100: #f5f5f5;
    --color-zinc-200: #e5e5e5;
    --color-zinc-300: #d4d4d4;
    --color-zinc-400: #a3a3a3;
    --color-zinc-500: #737373;
    --color-zinc-600: #525252;
    --color-zinc-700: #404040;
    --color-zinc-800: #262626;
    --color-zinc-900: #171717;
    --color-zinc-950: #0a0a0a;

    --color-accent: var(--color-neutral-800);
    --color-accent-content: var(--color-neutral-800);
    --color-accent-foreground: var(--color-white);
}

@layer theme {
    .dark {
        --color-accent: var(--color-white);
        --color-accent-content: var(--color-white);
        --color-accent-foreground: var(--color-neutral-800);
    }
}

@layer base {
    *,
    ::after,
    ::before,
    ::backdrop,
    ::file-selector-button {
        border-color: var(--color-gray-200, currentColor);
    }
}

[data-flux-field]:not(ui-radio, ui-checkbox) {
    @apply grid gap-2;
}

[data-flux-label] {
    @apply !mb-0 !leading-tight;
}

input:focus[data-flux-control],
textarea:focus[data-flux-control],
select:focus[data-flux-control] {
    @apply outline-hidden ring-2 ring-accent ring-offset-2 ring-offset-accent-foreground;
}

@supports (padding: env(safe-area-inset-top)) {
    @media (display-mode: standalone) {
        body {
            padding-left: env(safe-area-inset-left);
            padding-right: env(safe-area-inset-right);
        }
        body:not(:has([data-flux-sidebar])):not(:has([data-flux-header])) {
            padding-top: env(safe-area-inset-top);
            padding-bottom: env(safe-area-inset-bottom);
        }
        .pwa-safe-top {
            padding-top: env(safe-area-inset-top);
        }
        .pwa-safe-bottom {
            padding-bottom: env(safe-area-inset-bottom);
        }
    }
}

.safe-area-bottom {
    padding-bottom: env(safe-area-inset-bottom, 0px);
}
```

- [ ] **Step 2: Create head-landing.blade.php**

Copy `resources/views/partials/head-public.blade.php` to `resources/views/partials/head-landing.blade.php`, then change the `@vite` line:

```diff
- @vite(['resources/css/app.css', 'resources/js/app-public.js'])
+ @vite(['resources/css/landing.css', 'resources/js/app-public.js'])
```

Also add `@stack('preload')` before `@include('partials.meta-pixel')`.

- [ ] **Step 3: Add yield to guest layout for head partial override**

In `resources/views/components/layouts/guest.blade.php`, replace:
```blade
        @include('partials.head-public')
```
With:
```blade
        @include($headPartial ?? 'partials.head-public')
```

- [ ] **Step 4: Set landing page to use head-landing partial**

In `resources/views/components/public/⚡landing-page.blade.php`, find the `#[Layout(...)]` attribute and pass the head partial:

```php
#[Layout('layouts.guest', ['headPartial' => 'partials.head-landing'])]
```

- [ ] **Step 5: Add landing.css to Vite config**

In `vite.config.js`, add to the input array:

```js
'resources/css/landing.css',
```

- [ ] **Step 6: Build and compare CSS sizes**

Run: `npm run build`
Compare: `ls -la public/build/assets/app-*.css` vs `ls -la public/build/assets/landing-*.css`
Expected: landing CSS should be significantly smaller (60-100KB vs 381KB)

- [ ] **Step 7: Verify landing page renders correctly**

Load landing page in browser — all styling should look identical. Check other guest pages (FAQ, services, areas) still use `app.css` and render correctly.

- [ ] **Step 8: Commit**

```bash
git add resources/css/landing.css resources/views/partials/head-landing.blade.php resources/views/components/layouts/guest.blade.php "resources/views/components/public/⚡landing-page.blade.php" vite.config.js
git commit -m "perf: scoped landing CSS via Tailwind @source for smaller CSS bundle"
```

---

### Task 6: LCP Image Preload + CDN Image URLs on Landing Page

**Files:**
- Modify: `resources/views/components/public/⚡landing-page.blade.php:669-676` (before/after images)
- Modify: `resources/views/partials/head-landing.blade.php` (preload stack — already added in Task 5)

- [ ] **Step 1: Add LCP preload push to landing page template**

Near the top of the Blade template section (after `?>`) in `⚡landing-page.blade.php`, add:

```blade
@push('preload')
@if($this->showcasePhotos->first())
<link rel="preload" as="image" href="{{ $this->showcasePhotos->first()->before_url }}" fetchpriority="high">
@endif
@endpush
```

Note: This uses `before_url` which will be updated to CDN URLs in Task 7. For now it preloads whatever the current URL is.

- [ ] **Step 2: Add fetchpriority and dimensions to first before/after image**

In `⚡landing-page.blade.php` around line 671, update the first image:

```blade
<img src="{{ $pair->before_url }}"
     alt="{{ $pair->caption ? $pair->caption . ' - ' . __('Before') : __('Before') }}"
     class="absolute inset-0 w-full h-full object-cover transition-opacity duration-700"
     :class="showAfter ? 'opacity-0' : 'opacity-100'"
     @if($index === 0) fetchpriority="high" @endif
     loading="{{ $index === 0 ? 'eager' : 'lazy' }}" />
```

- [ ] **Step 3: Verify preload appears in page source**

Load landing page → View Source → search for `rel="preload"` — should see the before image URL.

- [ ] **Step 4: Commit**

```bash
git add "resources/views/components/public/⚡landing-page.blade.php"
git commit -m "perf: preload LCP image with fetchpriority=high"
```

---

### Task 7: Update Image Models for CDN URLs

**Files:**
- Modify: `app/Models/LandingPageShowcasePhoto.php:51-81`
- Modify: `app/Models/LandingPageSectionPhoto.php:46-73`

- [ ] **Step 1: Update LandingPageShowcasePhoto to use CDN**

In `app/Models/LandingPageShowcasePhoto.php`, replace the `getPhotoUrl` method (lines 51-81):

```php
public function getPhotoUrl(string $type): ?string
{
    $path = match ($type) {
        'before' => $this->before_photo_path,
        'after' => $this->after_photo_path,
        'before_thumbnail' => $this->before_thumbnail_path ?? $this->before_photo_path,
        'after_thumbnail' => $this->after_thumbnail_path ?? $this->after_photo_path,
        default => null,
    };

    if (! $path) {
        return null;
    }

    // Use Bunny CDN for optimized delivery when enabled
    if (config('services.bunny.enabled')) {
        return cdn_image($path, updatedAt: $this->updated_at);
    }

    $diskName = $this->disk ?? config('filesystems.default');
    $disk = Storage::disk($diskName);

    if (method_exists($disk, 'temporaryUrl')) {
        try {
            $cacheKey = "showcase_photo_url:{$this->id}:{$type}";

            return Cache::remember($cacheKey, now()->addMinutes(55), function () use ($disk, $path) {
                return $disk->temporaryUrl($path, now()->addMinutes(60));
            });
        } catch (\RuntimeException $e) {
            // Local disk doesn't support temporaryUrl
        }
    }

    return $disk->url($path);
}
```

- [ ] **Step 2: Update LandingPageSectionPhoto to use CDN**

In `app/Models/LandingPageSectionPhoto.php`, replace the `getPhotoUrl` method (lines 46-73):

```php
public function getPhotoUrl(string $type = 'original'): ?string
{
    $path = match ($type) {
        'thumbnail' => $this->thumbnail_path ?? $this->path,
        default => $this->path,
    };

    if (! $path) {
        return null;
    }

    // Use Bunny CDN for optimized delivery when enabled
    if (config('services.bunny.enabled')) {
        return cdn_image($path, updatedAt: $this->updated_at);
    }

    $diskName = $this->disk ?? config('filesystems.default');
    $disk = Storage::disk($diskName);

    try {
        if (method_exists($disk, 'temporaryUrl')) {
            $cacheKey = "section_photo_url:{$this->id}:{$type}";

            return Cache::remember($cacheKey, now()->addMinutes(55), function () use ($disk, $path) {
                return $disk->temporaryUrl($path, now()->addMinutes(60));
            });
        }
    } catch (\Throwable $e) {
        // Disk doesn't support temporaryUrl or missing config
    }

    return $disk->url($path);
}
```

- [ ] **Step 3: Run existing tests**

Run: `php artisan test --compact --filter="LandingPage"`
Expected: All tests pass (CDN is disabled by default, so behavior unchanged)

- [ ] **Step 4: Commit**

```bash
git add app/Models/LandingPageShowcasePhoto.php app/Models/LandingPageSectionPhoto.php
git commit -m "feat: serve landing page images through Bunny CDN when enabled"
```

---

### Task 8: Nginx Cache Headers (Manual — Forge Dashboard)

This is a manual step done through the Forge Dashboard.

- [ ] **Step 1: Open Forge Dashboard → Site → Nginx Configuration**

- [ ] **Step 2: Add cache header blocks inside the `server` block**

Add before the existing `location /` block:

```nginx
# Vite build assets — fingerprinted, cache forever
location /build/ {
    add_header Cache-Control "public, max-age=31536000, immutable";
    try_files $uri =404;
}

# Static assets — fonts, icons, manifest
location ~* \.(woff2?|ttf|eot|ico|webmanifest)$ {
    add_header Cache-Control "public, max-age=2592000";
    try_files $uri =404;
}
```

- [ ] **Step 3: Save and verify Nginx restarts without errors**

Forge will validate and restart Nginx. If it fails, revert immediately.

---

### Task 9: Bunny CDN Setup (Manual — Bunny Dashboard)

This is a manual infrastructure step.

- [ ] **Step 1: Create Bunny.net account** (if not already)

- [ ] **Step 2: Create a Pull Zone**
- Name: `paintingmadeeasy-media`
- Origin URL: `https://s3.us-central-1.wasabisys.com`
- Origin host header: `s3.us-central-1.wasabisys.com`

- [ ] **Step 3: Enable S3 Authentication**
- Go to Pull Zone → Security → S3 Authentication
- Enter Wasabi access key (read-only IAM scoped to bucket), secret key, region (`us-central-1`)

- [ ] **Step 4: Enable Bunny Optimizer**
- Go to Pull Zone → Optimizer → Enable
- Default quality: 85
- Auto WebP: enabled

- [ ] **Step 5: Add custom hostname**
- Go to Pull Zone → Custom Hostnames → Add `cdn.paintingmadeeasydenver.com`
- Add CNAME record in DNS: `cdn` → `paintingmadeeasy-media.b-cdn.net`
- Wait for SSL certificate auto-provisioning

- [ ] **Step 6: Set cache TTL**
- Edge cache: 30 days
- Browser cache: respect origin headers

- [ ] **Step 7: Update production .env**

```
BUNNY_CDN_URL=https://cdn.paintingmadeeasydenver.com/paintbucket
BUNNY_CDN_ENABLED=true
BUNNY_TOKEN_KEY=<your-pull-zone-token-from-security-settings>
```

- [ ] **Step 8: Test CDN URL**

Visit: `https://cdn.paintingmadeeasydenver.com/paintbucket/photos/any-known-image.jpg?width=400`
Expected: Returns a resized WebP image (check response Content-Type header)

- [ ] **Step 9: Clear config cache on production**

```bash
php artisan config:clear && php artisan config:cache
```

---

### Task 10: Build, Test & Deploy

- [ ] **Step 1: Run full build**

```bash
npm run build
```

Verify: `landing-*.css` exists and is significantly smaller than `app-*.css`

- [ ] **Step 2: Run test suite**

```bash
php artisan test --compact tests/Unit/CdnHelperTest.php
php artisan test --compact --filter="LandingPage"
```

Expected: All tests pass

- [ ] **Step 3: Format code**

```bash
vendor/bin/pint --dirty
```

- [ ] **Step 4: Final commit and push**

```bash
git add -A
git commit -m "perf: complete landing page performance optimization

- Bunny CDN helpers for optimized image delivery
- Self-hosted fonts with font-display: swap
- Deferred third-party scripts (GTM, GA, FB) until user interaction
- Lazy-loaded Leaflet map via IntersectionObserver
- Scoped landing CSS via Tailwind @source (smaller bundle)
- LCP image preload with fetchpriority=high
- Nginx cache headers for static assets"

git push origin main
```

- [ ] **Step 5: Re-run PageSpeed Insights**

After deploy completes and Bunny CDN is configured:
Visit: `https://pagespeed.web.dev/` → test `https://paintingmadeeasydenver.com`
Expected: Score 90+ mobile (up from 59)

# Performance Optimization Design Spec

## Problem

The landing page scores **59 on PageSpeed Insights mobile** with a catastrophic **40.8s LCP**. The total page weight is 9.3MB. The root causes are: unoptimized full-resolution images from Wasabi S3, third-party scripts blocking the main thread, no CDN or edge caching, missing HTTP cache headers, and oversized CSS.

## Goal

Reach **90+ PageSpeed mobile score** while keeping every feature, every tracking script, every image, and every interactive element. Nothing gets removed — everything loads smarter.

## Architecture Overview

Seven optimization layers applied app-wide:

1. **Bunny CDN + Optimizer** for all Wasabi S3 assets (images, videos, documents)
2. **Deferred third-party scripts** (GTM, GA, Facebook Pixel, Messenger)
3. **LCP image preloading** with `fetchpriority="high"`
4. **Self-hosted fonts** with `font-display: swap`
5. **Lazy-loaded Leaflet map** via IntersectionObserver
6. **Scoped landing CSS** via Tailwind v4 `@source`
7. **Nginx cache headers** for static assets

---

## Section 1: Bunny CDN + Optimizer

### What

Route all Wasabi S3 content through a Bunny CDN pull zone with image optimization enabled.

### Infrastructure Setup

1. Create a Bunny CDN pull zone:
   - **Origin URL**: `https://s3.us-central-1.wasabisys.com/paintbucket`
   - **Custom hostname**: `cdn.paintingmadeeasydenver.com`
   - **S3 Authentication**: Enable with Wasabi access key, secret key, region
   - **Bunny Optimizer**: Enable ($9.50/mo)
   - **Cache TTL**: 30 days for images, respect origin for dynamic content

2. DNS: Add CNAME record `cdn.paintingmadeeasydenver.com` → `<pullzone>.b-cdn.net`

3. SSL: Bunny auto-provisions a free SSL cert for the custom hostname

### Laravel Integration

Create a helper function and config:

**Config** (`config/services.php`):
```php
'bunny' => [
    'cdn_url' => env('BUNNY_CDN_URL', ''),
    'enabled' => env('BUNNY_CDN_ENABLED', false),
],
```

**Helper** (`app/Helpers/cdn.php`):
```php
function cdn_image(string $path, int $width = null, int $quality = 80): string
{
    if (!config('services.bunny.enabled') || !config('services.bunny.cdn_url')) {
        // Fallback to direct Wasabi URL
        return Storage::disk('photos')->url($path);
    }

    $url = config('services.bunny.cdn_url') . '/' . ltrim($path, '/');
    $params = ['quality' => $quality];
    if ($width) {
        $params['width'] = $width;
    }
    return $url . '?' . http_build_query($params);
}
```

**Responsive `srcset` helper**:
```php
function cdn_srcset(string $path, array $widths = [400, 800, 1200], int $quality = 80): string
{
    return collect($widths)
        ->map(fn($w) => cdn_image($path, $w, $quality) . " {$w}w")
        ->implode(', ');
}
```

### Model Changes

Update `LandingPageShowcasePhoto` and `LandingPageSectionPhoto` to use CDN URLs:

```php
// Replace temporaryUrl() approach with CDN approach for public images
public function getPhotoUrl(string $type): ?string
{
    $path = $this->{"{$type}_path"} ?? $this->{"{$type}_photo_path"};
    if (!$path) return null;

    return cdn_image($path);
}
```

For private images (estimates, jobs), use **Bunny Token Authentication**:
```php
function cdn_private(string $path, int $expiresInMinutes = 60): string
{
    $path = '/' . ltrim($path, '/');
    $expires = now()->addMinutes($expiresInMinutes)->timestamp;
    $securityKey = config('services.bunny.token_key');
    // Bunny requires raw binary SHA256, URL-safe base64 encoded
    $hashableBase = $securityKey . $path . $expires;
    $token = str_replace(['+', '/'], ['-', '_'], base64_encode(hash('sha256', $hashableBase, true)));
    return config('services.bunny.cdn_url') . $path . '?token=' . $token . '&expires=' . $expires;
}
```

### Security Model: Two-Layer Access

1. **Origin layer**: Bunny CDN has S3 credentials (read-only, scoped to the bucket) to fetch from private Wasabi objects on your behalf.
2. **End-user layer**: Token authentication restricts which URLs visitors can access. Only server-generated signed URLs are valid.

Use IAM-scoped credentials in Bunny (read-only on the specific bucket, not root Wasabi keys).

### Failure Recovery

If Bunny CDN goes down: set `BUNNY_CDN_ENABLED=false` in `.env` and run `php artisan config:clear`. The `cdn_image()` helper falls back to direct Wasabi URLs. Bunny CDN has a 99.99% SLA.

### Cache Invalidation

Use cache-busting via `updated_at` timestamp on image URLs:
```php
function cdn_image(string $path, int $width = null, int $quality = 80, ?Carbon $updatedAt = null): string
{
    // ... existing logic ...
    if ($updatedAt) {
        $params['v'] = $updatedAt->timestamp;
    }
    return $url . '?' . http_build_query($params);
}
```

When a photo is re-uploaded, the `updated_at` changes, producing a new URL that bypasses the CDN cache.

### Scope

- **All public images**: Landing page showcase, section photos, video thumbnails, property photos displayed publicly
- **All private images**: Estimate photos, job photos, customer documents — via token auth
- **Videos**: Edge-cached through CDN (no optimization, just faster delivery)
- **Documents/PDFs**: Edge-cached through CDN

### Cost

- Bunny Optimizer: $9.50/mo
- Bunny CDN bandwidth: ~$1-3/mo (estimated for small site traffic)
- Total: ~$10-13/mo

---

## Section 2: Deferred Third-Party Scripts

### What

Delay loading of GTM, GA, Facebook Pixel, and Messenger SDK until the user's first interaction or 5-second timeout.

### Implementation

**File**: `resources/views/components/layouts/guest.blade.php`

Replace the current inline GTM/GA scripts with a deferred loader:

```html
@production
<script>
(function() {
    var loaded = false;
    function loadThirdParty() {
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

    // Load on first user interaction
    ['scroll','mousemove','touchstart','keydown'].forEach(function(e) {
        window.addEventListener(e, loadThirdParty, {once: true, passive: true});
    });

    // Fallback: load after 5 seconds for passive visitors
    setTimeout(loadThirdParty, 5000);
})();
</script>
@endproduction
```

**File**: `resources/views/partials/meta-pixel.blade.php`

Same pattern — wrap Facebook Pixel initialization in the deferred loader or merge it into the same loader above.

**File**: `resources/views/partials/messenger-widget.blade.php`

The Messenger SDK is already async (`fbAsyncInit`). Move its `<script>` injection into the same deferred loader.

### Important: Preserve `<noscript>` Fallbacks

The GTM `<noscript>` iframe in the `<body>` of `guest.blade.php` (line 31) must remain unchanged — it tracks no-JS users/crawlers and is not render-blocking. Similarly, the Facebook Pixel `<noscript>` image tag in `meta-pixel.blade.php` must stay inline (not deferred) since it only fires for no-JS visitors.

### Why This Is Safe

- No user interacts with a page in under 1-2 seconds
- GTM fires `page_view` when it loads, capturing the visit
- Facebook Pixel fires `PageView` when it loads
- The 5-second timeout catches bots and users who don't interact
- The `<noscript>` fallbacks cover no-JS visitors independently
- Confirmed pattern used by high-performing sites (web.dev recommendation)

---

## Section 3: LCP Image Preloading

### What

Preload the hero before/after image so the browser starts downloading it immediately.

### Implementation

Use Blade stacks (server-side) to inject the preload link into the `<head>`.

**File**: `resources/views/partials/head-public.blade.php` — add before the closing of head content:

```html
@stack('preload')
```

**File**: `resources/views/components/public/⚡landing-page.blade.php` — in the Blade template:
```blade
@push('preload')
@if($this->showcasePhotos->first())
<link rel="preload" as="image" href="{{ cdn_image($this->showcasePhotos->first()->before_photo_path, 800, 85) }}" fetchpriority="high">
@endif
@endpush
```

Also add `fetchpriority="high"` and explicit `width`/`height` to the first `<img>` tag:

```html
<img src="{{ cdn_image($pair->before_photo_path, 800) }}"
     width="800" height="600"
     fetchpriority="high"
     alt="Before" />
```

---

## Section 4: Self-Hosted Fonts

### What

Download Instrument Sans WOFF2 files, self-host them, add `font-display: swap`.

### Implementation

1. Download 3 WOFF2 files from `fonts.bunny.net`:
   - `instrument-sans-latin-400-normal.woff2`
   - `instrument-sans-latin-500-normal.woff2`
   - `instrument-sans-latin-600-normal.woff2`

2. Save to `public/fonts/`

3. **File**: `resources/css/app.css` — add `@font-face` declarations:

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

4. **File**: `resources/views/partials/head-public.blade.php` — remove the Bunny Fonts link and preconnect:

```diff
- <link rel="preconnect" href="https://fonts.bunny.net">
- <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />
+ <link rel="preload" href="/fonts/instrument-sans-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
+ <link rel="preload" href="/fonts/instrument-sans-latin-500-normal.woff2" as="font" type="font/woff2" crossorigin>
```

Preload the 400 (body text) and 500 (primary UI weight) fonts. The 600 weight (headings) can load naturally — it's used sparingly and `font-display: swap` prevents blocking.

5. Do the same in `resources/views/partials/head.blade.php` for authenticated pages.

### Benefits

- Eliminates DNS lookup + TLS handshake to `fonts.bunny.net` (~100-200ms)
- `font-display: swap` prevents invisible text during font load
- Preloading the primary weight starts the download immediately
- Fonts cached forever with Nginx headers (they never change)

---

## Section 5: Lazy-Loaded Leaflet Map

### What

Defer loading of Leaflet JS/CSS and map tiles until the service area map scrolls into view.

### Implementation

**File**: `resources/views/partials/service-area-map.blade.php`

Wrap the Leaflet loading in an IntersectionObserver:

```html
<div id="service-area-map-container" class="h-[400px] bg-zinc-800 rounded-xl flex items-center justify-center">
    <span class="text-zinc-400 text-sm" id="map-placeholder">Loading map...</span>
</div>

<script>
(function() {
    var loaded = false;
    var container = document.getElementById('service-area-map-container');
    if (!container) return;

    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting && !loaded) {
                loaded = true;
                observer.disconnect();
                // Load Leaflet CSS
                var css = document.createElement('link');
                css.rel = 'stylesheet';
                css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                document.head.appendChild(css);
                // Load Leaflet JS then init map
                var js = document.createElement('script');
                js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                js.onload = function() { initServiceAreaMap(); };
                document.head.appendChild(js);
            }
        });
    }, { rootMargin: '300px' });

    observer.observe(container);
})();
</script>
```

Move the existing map initialization code into `initServiceAreaMap()`.

### Area Pages: Eager Loading

The `service-area-map.blade.php` partial is also used on individual area pages (`⚡area-page.blade.php`) where the map is primary content. Pass a `$lazy` parameter to control behavior:

```blade
{{-- Landing page: lazy --}}
@include('partials.service-area-map', ['cities' => $cities, 'lazy' => true])

{{-- Area page: eager --}}
@include('partials.service-area-map', ['cities' => $cities, 'lazy' => false])
```

When `$lazy` is false, load Leaflet immediately (current behavior).

---

## Section 6: Scoped Landing CSS

### What

Create a landing-page-specific CSS file that only includes Tailwind classes used on the landing page, reducing CSS from 381KB to ~60-80KB.

### Important: Guest Layout Shared by 17 Pages

The `head-public.blade.php` partial is used by all guest-layout pages (FAQ, Services, Area pages, etc.), not just the landing page. We cannot simply swap `app.css` for `landing.css` in `head-public.blade.php` — that would break 16 other pages.

**Solution**: Create a dedicated `head-landing.blade.php` that the landing page component uses, while all other guest pages continue using `head-public.blade.php` with `app.css`.

### Implementation

1. **Create**: `resources/css/landing.css`

```css
@import 'tailwindcss';
@import '../../vendor/livewire/flux/dist/flux.css';

/* Only scan landing page templates for class usage */
@source '../views/components/public/⚡landing-page.blade.php';
@source '../views/components/layouts/guest.blade.php';
@source '../views/partials/head-public.blade.php';
@source '../views/partials/service-area-map.blade.php';
@source '../views/partials/messenger-widget.blade.php';
@source '../../vendor/livewire/flux/stubs';

@theme {
    --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif, "Apple Color Emoji", "Segoe UI Emoji";
    /* Copy other theme values from app.css */
}
```

2. **Create**: `resources/views/partials/head-landing.blade.php` — copy of `head-public.blade.php` but loads `landing.css`:
```diff
- @vite(['resources/css/app.css', 'resources/js/app-public.js'])
+ @vite(['resources/css/landing.css', 'resources/js/app-public.js'])
```

3. **Edit**: `resources/views/components/public/⚡landing-page.blade.php` — use a `@push('head-override')` or override the layout's head partial:
```php
// In the PHP section, override the layout to use landing head
#[Layout('layouts.guest', ['headPartial' => 'partials.head-landing'])]
```
Or simpler: use `@section`/`@yield` pattern in the guest layout to allow the landing page to override which head partial loads.

4. **Edit**: `vite.config.js` — add `'resources/css/landing.css'` to input array

5. All other guest pages continue using `head-public.blade.php` with `app.css` — no changes needed.

---

## Section 7: Nginx Cache Headers

### What

Add proper Cache-Control headers via the Forge Nginx config.

### Implementation

In the Forge Dashboard → Site → Nginx Configuration, add inside the `server` block:

```nginx
# Vite build assets — fingerprinted, cache forever
location /build/ {
    add_header Cache-Control "public, max-age=31536000, immutable";
    try_files $uri =404;
}

# Static assets — fonts, icons, manifest
location ~* \.(woff2?|ttf|eot|ico|svg|webmanifest|json)$ {
    add_header Cache-Control "public, max-age=2592000";
    try_files $uri =404;
}
```

Images are served from Bunny CDN (not Nginx), so image headers are configured in Bunny's pull zone settings.

---

## Expected Results

| Metric | Before | After (Expected) |
|--------|--------|-------------------|
| PageSpeed Mobile | 59 | 90+ |
| LCP | 40.8s | 1.5-2.5s |
| FCP | 2.9s | 1.0-1.5s |
| TBT | 390ms | <200ms |
| Speed Index | 3.8s | 2.0-3.0s |
| Page Weight | 9.3MB | ~800KB-1.2MB |
| Initial JS | ~720KB | ~200KB (Livewire+Flux+app-public) |

## Monthly Cost

| Service | Cost |
|---------|------|
| Bunny Optimizer | $9.50/mo |
| Bunny CDN bandwidth | ~$1-3/mo |
| **Total** | **~$10-13/mo** |

## Files to Modify

| File | Change |
|------|--------|
| `config/services.php` | Add Bunny CDN config |
| `.env` / `.env.example` | Add `BUNNY_CDN_URL`, `BUNNY_CDN_ENABLED`, `BUNNY_TOKEN_KEY` |
| `app/Helpers/cdn.php` | **Create** — CDN URL helpers |
| `composer.json` | Register helpers autoload |
| `app/Models/LandingPageShowcasePhoto.php` | Use CDN URLs |
| `app/Models/LandingPageSectionPhoto.php` | Use CDN URLs |
| `resources/views/components/layouts/guest.blade.php` | Deferred third-party scripts |
| `resources/views/partials/meta-pixel.blade.php` | Merge into deferred loader |
| `resources/views/partials/messenger-widget.blade.php` | Merge into deferred loader |
| `resources/views/partials/head-public.blade.php` | Self-hosted fonts, preload stack |
| `resources/views/partials/head-landing.blade.php` | **Create** — landing-specific head with scoped CSS |
| `resources/views/partials/head.blade.php` | Self-hosted fonts |
| `resources/views/partials/service-area-map.blade.php` | Lazy-load with IntersectionObserver |
| `resources/views/components/public/⚡landing-page.blade.php` | LCP preload, CDN image URLs, responsive srcset |
| `resources/css/landing.css` | **Create** — scoped Tailwind |
| `resources/css/app.css` | Add @font-face declarations |
| `public/fonts/` | **Create** — self-hosted WOFF2 files |
| `vite.config.js` | Add landing.css entry point |
| Nginx config (via Forge) | Cache headers |
| Bunny Dashboard | Pull zone + Optimizer setup |

## Verification

1. Bunny CDN: Visit `cdn.paintingmadeeasydenver.com/photos/any-image.jpg?width=400` — should return WebP
2. `npm run build` — verify landing.css is generated and significantly smaller than app.css
3. DevTools Network (landing page): Verify no GTM/GA/FB loads until scroll/click
4. DevTools Network: Verify Leaflet doesn't load until map scrolls into view
5. DevTools: Check fonts load from `/fonts/` not `fonts.bunny.net`
6. Response headers: Verify `Cache-Control: immutable` on `/build/` assets
7. PageSpeed Insights: Re-run and verify score improvement
8. `php artisan test --compact` — verify no regressions

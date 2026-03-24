<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
<meta name="csrf-token" content="{{ csrf_token() }}">
@auth
<meta name="user-id" content="{{ auth()->id() }}">
<meta name="user-type" content="{{ auth()->user()->type }}">
<meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key') }}">
@endauth

<title>{{ $title ?? config('app.name') }}</title>

<meta name="description" content="@yield('meta_description', 'Denver house painting with AI color preview, instant pricing & real-time tracking. Free estimates.')">
<meta property="og:title" content="@yield('og_title', $title ?? config('app.name'))">
<meta property="og:description" content="@yield('og_description', 'Denver house painting with AI color preview, instant pricing & real-time tracking. Free estimates.')">
<meta property="og:url" content="@yield('og_url', url()->current())">
<meta property="og:type" content="@yield('og_type', 'website')">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="@yield('og_title', $title ?? config('app.name'))">
<meta name="twitter:description" content="@yield('og_description', 'Denver house painting with AI color preview, instant pricing & real-time tracking. Free estimates.')">
@hasSection('canonical')
<link rel="canonical" href="@yield('canonical')">
@endif
@hasSection('robots')
<meta name="robots" content="@yield('robots')">
@endif

@stack('meta')
@stack('seo_meta')
@stack('structured_data')

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#3b82f6">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Painting Made Easy Denver">

<link rel="preload" href="/fonts/instrument-sans-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/fonts/instrument-sans-latin-500-normal.woff2" as="font" type="font/woff2" crossorigin>

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

@include('partials.meta-pixel')

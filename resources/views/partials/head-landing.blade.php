<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
<meta name="csrf-token" content="{{ csrf_token() }}">

<title>@hasSection('meta_title')@yield('meta_title')@else{{ $title ?? config('app.name') }}@endif</title>

<meta name="description" content="@yield('meta_description', 'Denver house painting with AI color preview, instant pricing & real-time tracking. Free estimates.')">
<meta property="og:title" content="@hasSection('og_title')@yield('og_title')@elseif(View::hasSection('meta_title'))@yield('meta_title')@else{{ $title ?? config('app.name') }}@endif">
<meta property="og:description" content="@yield('og_description', 'Denver house painting with AI color preview, instant pricing & real-time tracking. Free estimates.')">
<meta property="og:url" content="@yield('og_url', url()->current())">
<meta property="og:type" content="@yield('og_type', 'website')">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="@hasSection('og_title')@yield('og_title')@elseif(View::hasSection('meta_title'))@yield('meta_title')@else{{ $title ?? config('app.name') }}@endif">
<meta name="twitter:description" content="@yield('og_description', 'Denver house painting with AI color preview, instant pricing & real-time tracking. Free estimates.')">
<link rel="canonical" href="@yield('canonical', url()->current())">
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

@vite(['resources/css/landing.css', 'resources/js/app-public.js'])
@fluxAppearance

@stack('preload')
@include('partials.meta-pixel')

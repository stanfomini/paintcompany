<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
<meta name="csrf-token" content="{{ csrf_token() }}">
@auth
<meta name="user-id" content="{{ auth()->id() }}">
<meta name="user-type" content="{{ auth()->user()->type }}">
@endauth

<title>{{ $title ?? config('app.name') }}</title>

@stack('meta')

@hasSection('meta_description')
<meta name="description" content="@yield('meta_description')">
@endif
@hasSection('canonical')
<link rel="canonical" href="@yield('canonical')">
@endif
@hasSection('robots')
<meta name="robots" content="@yield('robots')">
@endif

{{-- Open Graph --}}
@hasSection('og_title')
<meta property="og:title" content="@yield('og_title')">
@endif
@hasSection('meta_description')
<meta property="og:description" content="@yield('meta_description')">
@endif
@hasSection('og_image')
<meta property="og:image" content="@yield('og_image')">
@endif
@hasSection('og_url')
<meta property="og:url" content="@yield('og_url')">
@endif
@hasSection('og_type')
<meta property="og:type" content="@yield('og_type')">
@endif
<meta property="og:locale" content="{{ str_replace('_', '-', app()->getLocale()) }}">

{{-- Twitter Card --}}
<meta name="twitter:card" content="summary_large_image">
@hasSection('og_title')
<meta name="twitter:title" content="@yield('og_title')">
@endif
@hasSection('meta_description')
<meta name="twitter:description" content="@yield('meta_description')">
@endif
@hasSection('og_image')
<meta name="twitter:image" content="@yield('og_image')">
@endif

@stack('structured_data')

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

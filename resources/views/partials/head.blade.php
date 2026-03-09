<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
<meta name="csrf-token" content="{{ csrf_token() }}">
@auth
<meta name="user-id" content="{{ auth()->id() }}">
<meta name="user-type" content="{{ auth()->user()->type }}">
<meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key') }}">
@endauth

<title>{{ $title ?? config('app.name') }}</title>

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

<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

@include('partials.meta-pixel')

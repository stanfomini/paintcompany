<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $authUser = auth()->user()
            ?? auth('customer')->user()
            ?? auth('crew')->user()
            ?? auth('agent')->user();
    @endphp
    @if($authUser)
    <meta name="user-id" content="{{ $authUser->id }}">
    <meta name="user-type" content="{{ $authUser->type }}">
    @endif

    <title>{{ $title ?? 'Video Call' }} - {{ config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="bg-zinc-900 antialiased overflow-hidden">
    {{ $slot }}
    @fluxScripts
</body>
</html>

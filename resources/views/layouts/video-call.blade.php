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
    {{-- Always set user-id so app.js creates Echo (needed for WebRTC signaling).
         For guests, use "guest" — the component overrides the channel authorizer. --}}
    <meta name="user-id" content="{{ $authUser?->id ?? 'guest' }}">
    @if($authUser)
    <meta name="user-type" content="{{ $authUser->type }}">
    @endif

    {{-- Reverb config for Echo initialization (guests need these to create Echo) --}}
    <meta name="reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
    <meta name="reverb-host" content="{{ config('broadcasting.connections.reverb.options.host') }}">
    <meta name="reverb-port" content="{{ config('broadcasting.connections.reverb.options.port', 443) }}">
    <meta name="reverb-scheme" content="{{ config('broadcasting.connections.reverb.options.scheme', 'https') }}">

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

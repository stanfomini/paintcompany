<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        @isset($structuredData)
        <script type="application/ld+json">
        {!! $structuredData !!}
        </script>
        @endisset
    </head>
    <body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-900">
        {{ $slot }}
        @fluxScripts
    </body>
</html>
